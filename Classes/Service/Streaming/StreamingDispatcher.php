<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailPolicyResolver;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Netresearch\NrLlm\Service\Guardrail\StreamRedactableInterface;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;
use Traversable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;

/**
 * Streaming request lifecycle (ADR-062).
 *
 * A streamed provider call cannot go through {@see \Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline}:
 * a PHP generator is lazy, so wrapping it as the pipeline terminal makes every
 * middleware run against a not-yet-started stream — Budget would gate nothing,
 * Usage would record zero tokens, Telemetry would measure a near-zero latency.
 * That is exactly why streaming used to bypass the pipeline (ADR-026) and,
 * with it, budget enforcement and usage/telemetry accounting.
 *
 * This dispatcher restores that lifecycle for streams while keeping the public
 * {@see Generator}<int, string, mixed, void> contract intact — it is a
 * wrapping generator, not a replacement of the provider's:
 *
 *  1. **Budget pre-flight (eager).** {@see self::stream()} runs the same
 *     {@see BudgetServiceInterface::check()} gate as {@see BudgetMiddleware}
 *     BEFORE any generator is handed back, so an over-budget user never opens
 *     a stream. It throws {@see BudgetExceededException} at call time, not on
 *     first iteration.
 *  2. **Fallback before the first chunk.** Provider selection is retried across
 *     the configuration's fallback chain while priming the inner generator
 *     (the pre-first-chunk window). Once a chunk has been yielded to the caller
 *     a provider swap is impossible, so fallback stops there — this is the one
 *     structural difference from the non-streaming pipeline.
 *  3. **Drain accounting (lazy).** While the caller drains the stream the
 *     wrapper counts the completion text and records the time-to-first-token.
 *  4. **finally-block settlement.** Usage ({@see UsageTrackerServiceInterface})
 *     and telemetry ({@see TelemetryRepositoryInterface}) are written in a
 *     `finally`, so they land whether the stream completes, throws, or is
 *     abandoned early (client disconnect / consumer `break`) — PHP runs the
 *     `finally` when a suspended generator is destroyed. An abandoned stream
 *     therefore records the PARTIAL tokens actually produced, never zero.
 *
 * Token counts are ESTIMATED (≈4 chars / token, the heuristic already used by
 * {@see \Netresearch\NrLlm\Domain\Model\RenderedPrompt::estimateTokens()}):
 * the seven streaming providers yield only text deltas and no usage frame, so a
 * real per-token figure is not available on this path. Recording an estimate is
 * a deliberate improvement over the previous state, which recorded nothing at
 * all. Exact stream usage would require provider-level `include_usage` support
 * and is tracked as a follow-up in ADR-062.
 */
final readonly class StreamingDispatcher
{
    /**
     * The resolved provider identifier for an ad-hoc stream (no configuration
     * entity carries a provider type). Read for usage attribution when the
     * served configuration's provider type is empty.
     */
    public const METADATA_PROVIDER = 'streamProvider';

    /**
     * Character length of the prompt, computed by the caller (which holds the
     * messages) so this dispatcher can estimate prompt tokens without seeing
     * the payload — keeping the ADR-026 "context carries no payload" rule.
     */
    public const METADATA_PROMPT_CHARS = 'streamPromptChars';

    private const CHARS_PER_TOKEN = 4;

    /**
     * Upper bound on the completion text buffered for the end-of-stream guardrail
     * audit (ADR-086). Streaming deliberately avoids holding the whole response
     * (see the class doc block); a secret a model echoes appears near where it
     * was given, so the leading window is enough to screen while keeping the
     * memory cost bounded on a pathologically long stream.
     */
    private const MAX_GUARDRAIL_BUFFER_BYTES = 50000;

    /**
     * The unfiltered baseline (ADR-106): the per-call filtered live-redactor and
     * audit lists are derived as locals in {@see self::drain()} so nothing is
     * written back to instance state — a second stream() in the same request
     * cannot see a stale narrower filter.
     *
     * @var list<GuardrailInterface>
     */
    private array $guardrails;

    /**
     * @param iterable<GuardrailInterface> $guardrails
     */
    public function __construct(
        private BudgetServiceInterface $budgetService,
        private UsageTrackerServiceInterface $usageTracker,
        private TelemetryRepositoryInterface $telemetryRepository,
        private LlmConfigurationRepository $repository,
        private LoggerInterface $logger,
        private Context $context,
        private ExtensionConfiguration $extensionConfiguration,
        // Autowired in production; the no-op default keeps the lean test wiring
        // working (a null/empty selection runs all guardrails, unchanged from
        // before ADR-106).
        private GuardrailPolicyResolver $policyResolver = new GuardrailPolicyResolver(new GuardrailRegistry([], [])),
        #[AutowireIterator(GuardrailInterface::TAG_NAME)]
        iterable $guardrails = [],
    ) {
        // Materialise the unfiltered baseline once; drain() derives the per-call
        // live-redactor and audit lists from it via the policy resolver (ADR-106).
        $this->guardrails = array_values($guardrails instanceof Traversable ? iterator_to_array($guardrails) : $guardrails);
    }

    /**
     * Enter the streaming lifecycle.
     *
     * Budget is checked eagerly here (before a generator exists) so an
     * over-budget caller is rejected at call time; the returned generator then
     * lazily drains, accounts, and settles usage/telemetry.
     *
     * @param callable(LlmConfiguration): Generator<int, string, mixed, void> $open
     *                                                                              opens a fresh inner stream for the given configuration (resolve
     *                                                                              adapter, shape messages, merge options). Called once per fallback
     *                                                                              candidate. Mirrors the pipeline terminal shape.
     *
     * @throws BudgetExceededException when the pre-flight budget check denies the call
     *
     * @return Generator<int, string, mixed, void>
     */
    public function stream(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $open,
    ): Generator {
        $this->assertWithinBudget($context, $configuration);

        return $this->drain($context, $configuration, $open);
    }

    /**
     * @param callable(LlmConfiguration): Generator<int, string, mixed, void> $open
     *
     * @return Generator<int, string, mixed, void>
     */
    private function drain(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $open,
    ): Generator {
        $startNs         = hrtime(true);
        $firstTokenNs    = null;
        $completionBytes = 0;
        $completion      = '';
        // Live redactors keyed on the REQUESTED config (the window must exist
        // before openWithFallback picks $served). They are always mandatory
        // (secret-redaction is the sole StreamRedactableInterface), so the
        // per-config filter never drops one and the requested-vs-served
        // distinction is moot for them (ADR-106).
        $streamRedactors = array_values(array_filter(
            $this->policyResolver->filter($this->guardrails, $configuration),
            static fn(GuardrailInterface $g): bool => $g instanceof StreamRedactableInterface,
        ));
        $redacts         = $streamRedactors !== [];
        // Live redaction (ADR-088): a bounded, self-certifying sliding window that
        // masks secrets — including one split across chunk boundaries or positioned
        // arbitrarily far into a long stream — with bounded memory and no O(n^2)
        // rescan, and never passes a raw secret byte through. null when no
        // redaction-capable guardrail is registered (verbatim pass-through).
        $window          = $redacts
            ? new StreamRedactionWindow(fn(string $s): string => $this->redactStream($streamRedactors, $s))
            : null;
        $served          = $configuration;
        $success         = false;
        $errorClass      = '';

        try {
            [$inner, $served] = $this->openWithFallback($context, $configuration, $open);

            while ($inner->valid()) {
                if ($firstTokenNs === null) {
                    $firstTokenNs = hrtime(true);
                }
                $chunk = $inner->current();
                // Usage/telemetry count the RAW provider output. A bounded leading
                // window is buffered raw for the end-of-stream audit (ADR-086).
                $completionBytes += \strlen($chunk);
                if (\strlen($completion) < self::MAX_GUARDRAIL_BUFFER_BYTES) {
                    $completion .= $chunk;
                }

                if ($window === null) {
                    // No redaction-capable guardrail: verbatim pass-through.
                    yield $chunk;
                    $inner->next();
                    continue;
                }

                foreach ($window->push($chunk) as $delta) {
                    yield $delta;
                }
                $inner->next();
            }

            // Flush the redacted remainder once the stream is complete.
            if ($window !== null) {
                foreach ($window->flush() as $delta) {
                    yield $delta;
                }
            }

            $success = true;
            // End-of-stream audit keyed on the SERVED config (that produced the
            // content), filtered per stream (ADR-106): a fallback swap can change
            // which optional audit guardrails apply.
            $auditGuardrails = $this->policyResolver->filter($this->guardrails, $served);
            $this->screenStreamedOutput($context, $served, $completion, $auditGuardrails);
        } catch (Throwable $e) {
            $errorClass = $e::class;

            throw $e;
        } finally {
            $this->settle(
                $context,
                $configuration,
                $served,
                $completionBytes,
                $startNs,
                $firstTokenNs,
                $success,
                $errorClass,
            );
        }
    }

    /**
     * Open the first configuration whose stream primes without a retryable
     * failure, walking the primary's fallback chain (shallow, like
     * {@see \Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware}). Priming
     * (`rewind()`) is the pre-first-chunk window: a connection/rate-limit error
     * here can still be answered by a sibling provider because nothing has been
     * yielded to the caller yet. A non-retryable error (misconfiguration,
     * unsupported feature, client 4xx) bubbles up unchanged.
     *
     * @param callable(LlmConfiguration): Generator<int, string, mixed, void> $open
     *
     * @return array{0: Generator<int, string, mixed, void>, 1: LlmConfiguration} the primed stream and the configuration that served it
     */
    private function openWithFallback(
        ProviderCallContext $context,
        LlmConfiguration $primary,
        callable $open,
    ): array {
        $candidates    = $this->candidates($context, $primary);
        $lastRetryable = null;

        foreach ($candidates as $index => $configuration) {
            if ($index > 0) {
                // Count every dispatched sibling BEFORE priming (the primary is
                // not counted), so a fallback that then fails retryably or
                // exhausts the chain is still counted — mirroring
                // FallbackMiddleware, which records before each $next($fallback).
                $context->telemetrySignals->recordFallbackAttempt();
            }

            $stream = $open($configuration);

            try {
                // Prime: runs the provider up to its first yield (HTTP request,
                // first delta). The one point a stream can still be re-routed.
                $stream->rewind();
            } catch (Throwable $e) {
                if (!$this->isRetryable($e)) {
                    throw $e;
                }

                $lastRetryable = $e;
                $this->logger->warning(
                    'LLM streaming attempt failed before first chunk, trying next configuration',
                    $this->logContext($context, [
                        'configuration'  => $configuration->getIdentifier(),
                        'exception'      => $e,
                        'exceptionClass' => $e::class,
                    ]),
                );

                continue;
            }

            return [$stream, $configuration];
        }

        // Every candidate failed with a retryable error. Surface the last one.
        throw $lastRetryable ?? new ProviderConnectionException(
            'Streaming fallback chain exhausted with no attemptable configuration',
            5825551327,
        );
    }

    /**
     * Primary first, then each active, resolvable fallback configuration. The
     * primary's own identifier is filtered out of the chain (no self-retry) and
     * missing / inactive fallbacks are skipped with a log line, exactly as
     * {@see \Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware} does.
     *
     * @return non-empty-list<LlmConfiguration>
     */
    private function candidates(ProviderCallContext $context, LlmConfiguration $primary): array
    {
        $candidates = [$primary];

        $chain = $primary->getFallbackChainDTO()->without($primary->getIdentifier());
        foreach ($chain->configurationIdentifiers as $identifier) {
            $fallback = $this->repository->findOneByIdentifier($identifier);
            if ($fallback === null || !$fallback->isActive()) {
                $this->logger->warning(
                    'LLM streaming fallback configuration missing or inactive, skipping',
                    $this->logContext($context, ['configuration' => $identifier]),
                );

                continue;
            }

            $candidates[] = $fallback;
        }

        return $candidates;
    }

    private function isRetryable(Throwable $e): bool
    {
        // Shared taxonomy (ADR-095) — the same decision the fallback middleware
        // and the circuit breaker make, so the streaming path cannot drift from
        // them. Previously this retried only connection and 429.
        return FailureClassifier::classify($e)->isRetryable();
    }

    /**
     * @throws BudgetExceededException when the pre-flight check denies the call
     */
    private function assertWithinBudget(ProviderCallContext $context, LlmConfiguration $configuration): void
    {
        $result = $this->budgetService->check(
            $this->readInt($context, BudgetMiddleware::METADATA_BE_USER_UID),
            $this->readFloat($context, BudgetMiddleware::METADATA_PLANNED_COST),
            $configuration,
        );

        if (!$result->allowed) {
            throw new BudgetExceededException($result);
        }
    }

    /**
     * Record usage and telemetry once the stream is done, in every exit path.
     *
     * Fail-soft: settlement runs inside the drain generator's `finally`, so a
     * throwable escaping here would (per PHP `finally` semantics) replace the
     * provider exception the caller must see. The whole body — including the
     * logger, which can itself throw — is therefore guarded.
     */
    private function settle(
        ProviderCallContext $context,
        LlmConfiguration $requested,
        LlmConfiguration $served,
        int $completionBytes,
        int|float $startNs,
        int|float|null $firstTokenNs,
        bool $success,
        string $errorClass,
    ): void {
        try {
            if (!$success && $errorClass === '') {
                // finally reached without success and without an exception: the
                // consumer abandoned the generator (client disconnect / early
                // break) and PHP is destroying it (ADR-062 requirement 4).
                $this->logger->info(
                    'LLM stream aborted before completion (client disconnect or early break)',
                    $this->logContext($context, ['completionChars' => $completionBytes]),
                );
            }

            // Usage is recorded when the stream produced output — on success
            // (matching the non-streaming UsageMiddleware, which records after a
            // successful call) and on a partial/aborted stream that still
            // yielded tokens (the "record even on abort" requirement). A total
            // pre-first-chunk failure produced nothing to bill, so it is skipped
            // just as a failed non-streaming call records no usage.
            if ($success || $completionBytes > 0) {
                $this->recordUsage($context, $served, $completionBytes);
            }

            $this->recordTelemetry(
                $context,
                $requested,
                $success,
                $errorClass,
                $this->elapsedMs($startNs),
                $firstTokenNs !== null ? $this->elapsedMs($startNs, $firstTokenNs) : null,
            );
        } catch (Throwable $e) {
            try {
                $this->logger->error(
                    'Failed to settle LLM streaming usage/telemetry',
                    $this->logContext($context, ['exception' => $e]),
                );
            } catch (Throwable) {
                // Nothing safe left to do; never let accounting break the call.
            }
        }
    }

    /**
     * End-of-stream guardrail audit (ADR-086).
     *
     * The pipeline's {@see \Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware}
     * never runs on a streamed response — a lazy generator cannot be the pipeline
     * terminal (see the class doc block) — so streamed output would otherwise be
     * a guardrail blind spot. This screens the assembled completion once the
     * stream finishes and records any non-ALLOW verdict.
     *
     * It is an AUDIT, not enforcement: the chunks have already been yielded to
     * the caller, so a DENY / REDACT cannot retract or live-redact them. Catching
     * a secret mid-stream would need a delta-oriented guardrail contract, a
     * deliberate ADR-086 follow-up. Fail-soft — the stream already succeeded, so
     * this never throws (a broken guardrail must not turn a delivered response
     * into an error).
     */
    /**
     * @param list<GuardrailInterface> $guardrails the config-filtered audit guardrails
     */
    private function screenStreamedOutput(
        ProviderCallContext $context,
        LlmConfiguration $served,
        string $completion,
        array $guardrails,
    ): void {
        if ($completion === '') {
            return;
        }

        try {
            $response = new CompletionResponse(
                content: $completion,
                model: $served->getModelId(),
                usage: UsageStatistics::fromTokens(0, 0),
            );

            foreach ($guardrails as $guardrail) {
                $verdict = $guardrail->checkOutput($response);
                if ($verdict->verdict === GuardrailVerdict::ALLOW) {
                    continue;
                }

                $this->logger->warning(
                    // REDACT was already applied to the live stream (ADR-088); this
                    // records that a policy matched, and is the only signal for a
                    // DENY / REQUIRE_APPROVAL, which cannot retract a sent stream.
                    'Streamed LLM response matched a guardrail (REDACT masked live where possible; a stream cannot be retracted)',
                    $this->logContext($context, [
                        'guardrail' => $guardrail::class,
                        'verdict'   => $verdict->verdict->value,
                        'reason'    => $verdict->reason,
                    ]),
                );
            }
        } catch (Throwable $e) {
            try {
                $this->logger->error(
                    'Failed to screen streamed LLM output for guardrails',
                    $this->logContext($context, ['exception' => $e]),
                );
            } catch (Throwable) {
                // Never let guardrail auditing break a delivered stream.
            }
        }
    }

    /**
     * Apply the output guardrails' REDACT verdicts to a text fragment (ADR-088).
     *
     * Always called on the RAW accumulated completion (never on already-redacted
     * output), so a secret is re-matched in full on every chunk regardless of how
     * it was split — this is what lets {@see self::drain()} mask a boundary-split
     * secret without an earlier marker orphaning its tail. Only REDACT is applied;
     * DENY / REQUIRE_APPROVAL cannot retract a sent stream and are left to the
     * end-of-stream audit ({@see self::screenStreamedOutput()}). Fail-soft: a
     * guardrail that throws leaves the fragment unchanged rather than breaking the
     * stream.
     */
    /**
     * @param list<GuardrailInterface&StreamRedactableInterface> $redactors the config-filtered live redactors
     */
    private function redactStream(array $redactors, string $text): string
    {
        foreach ($redactors as $guardrail) {
            try {
                $verdict = $guardrail->checkOutput(new CompletionResponse($text, '', UsageStatistics::fromTokens(0, 0)));
            } catch (Throwable) {
                continue;
            }

            if ($verdict->verdict === GuardrailVerdict::REDACT && $verdict->redactedContent !== null) {
                $text = $verdict->redactedContent;
            }
        }

        return $text;
    }

    private function recordUsage(
        ProviderCallContext $context,
        LlmConfiguration $served,
        int $completionBytes,
    ): void {
        $promptTokens     = $this->estimateTokens($this->readInt($context, self::METADATA_PROMPT_CHARS));
        $completionTokens = $this->estimateTokens($completionBytes);

        $model    = $served->getLlmModel();
        $modelUid = $model?->getUid() ?? 0;

        $cost = ($model !== null && $model->hasPricing())
            ? $model->estimateCost($promptTokens, $completionTokens)
            : null;

        /** @var array{tokens: int, promptTokens: int, completionTokens: int, cost?: float} $metrics */
        $metrics = [
            'tokens'           => $promptTokens + $completionTokens,
            'promptTokens'     => $promptTokens,
            'completionTokens' => $completionTokens,
        ];
        if ($cost !== null) {
            $metrics['cost'] = $cost;
        }

        $configUid = $served->getUid();

        $this->usageTracker->trackUsage(
            serviceType: $context->operation->value,
            provider: $this->resolveProvider($context, $served),
            metrics: $metrics,
            configurationUid: ($configUid !== null && $configUid > 0) ? $configUid : null,
            modelUid: $modelUid,
            modelId: $served->getModelId(),
            taskUid: $this->readInt($context, UsageMiddleware::METADATA_TASK_UID),
            beUserUid: $this->readNullableInt($context, BudgetMiddleware::METADATA_BE_USER_UID),
        );
    }

    private function recordTelemetry(
        ProviderCallContext $context,
        LlmConfiguration $requested,
        bool $success,
        string $errorClass,
        int $latencyMs,
        ?int $timeToFirstTokenMs,
    ): void {
        if (!$this->telemetryEnabled()) {
            return;
        }

        // Attribution mirrors the non-streaming TelemetryMiddleware: the row
        // names the REQUESTED primary configuration; a fallback swap shows as
        // fallback_attempts > 0, while the provider/model that actually served
        // live in the usage table. cacheHit is always false — streaming never
        // caches.
        $this->telemetryRepository->record(new TelemetryRecord(
            correlationId: $context->correlationId,
            operation: $context->operation->value,
            provider: $requested->getProviderType(),
            model: $requested->getModelId(),
            configurationIdentifier: $requested->getIdentifier(),
            beUser: $this->resolveBeUser($context),
            success: $success,
            errorClass: $errorClass,
            latencyMs: $latencyMs,
            cacheHit: false,
            fallbackAttempts: $context->telemetrySignals->fallbackAttempts,
            timeToFirstTokenMs: $timeToFirstTokenMs,
        ));
    }

    private function resolveProvider(ProviderCallContext $context, LlmConfiguration $served): string
    {
        $fromConfig = $served->getProviderType();
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        $fromMetadata = $context->metadata[self::METADATA_PROVIDER] ?? null;
        if (\is_string($fromMetadata) && $fromMetadata !== '') {
            return $fromMetadata;
        }

        return 'unknown';
    }

    /**
     * Backend-user attribution: caller-supplied uid (same key BudgetMiddleware
     * enforces on), else the ambient backend.user aspect, else 0 (CLI /
     * scheduler / unauthenticated) — mirroring TelemetryMiddleware.
     */
    private function resolveBeUser(ProviderCallContext $context): int
    {
        $fromMetadata = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null;
        if (\is_int($fromMetadata)) {
            return $fromMetadata;
        }

        try {
            return (int)$this->context->getAspect('backend.user')->get('id');
        } catch (AspectNotFoundException) {
            return 0;
        }
    }

    /**
     * The correlation id + operation every streaming log line carries, so the
     * two keys live in one place rather than being repeated at each call site.
     * Per-event fields merge on top.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function logContext(ProviderCallContext $context, array $extra = []): array
    {
        return [
            'correlationId' => $context->correlationId,
            'operation'     => $context->operation->value,
            ...$extra,
        ];
    }

    /**
     * Rough token estimate from a character count (≈4 chars / token), the same
     * heuristic RenderedPrompt / GeminiProvider already use. Streaming providers
     * expose no exact usage frame on this path; see the class doc block.
     */
    private function estimateTokens(int $chars): int
    {
        if ($chars <= 0) {
            return 0;
        }

        return (int)\ceil($chars / self::CHARS_PER_TOKEN);
    }

    private function elapsedMs(int|float $startNs, int|float|null $endNs = null): int
    {
        $endNs ??= hrtime(true);

        return (int)(($endNs - $startNs) / 1_000_000);
    }

    private function readInt(ProviderCallContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }

    private function readNullableInt(ProviderCallContext $context, string $key): ?int
    {
        $value = $context->metadata[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    private function readFloat(ProviderCallContext $context, string $key): float
    {
        $value = $context->metadata[$key] ?? null;

        return (\is_float($value) || \is_int($value)) ? (float)$value : 0.0;
    }

    /**
     * Telemetry defaults ON, disabled only by an explicit falsey
     * `telemetry.enabled` extension setting — identical to TelemetryMiddleware
     * so a streamed run and a pipelined run share one toggle.
     */
    private function telemetryEnabled(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return true;
        }

        $telemetry = \is_array($config['telemetry'] ?? null) ? $config['telemetry'] : [];
        if (!\array_key_exists('enabled', $telemetry)) {
            return true;
        }

        return (bool)$telemetry['enabled'];
    }
}
