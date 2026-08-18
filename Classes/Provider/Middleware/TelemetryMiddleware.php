<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrLlm\Service\Telemetry\ProviderRetryCounter;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;

/**
 * Outermost pipeline layer: records one telemetry row per run, ALWAYS (ADR-058).
 *
 * This is the failure-rate / latency observer the UsageMiddleware doc block
 * leaves open ("The middleware never runs when $next throws: failed calls are
 * not tracked here. If failure-rate telemetry is needed later, a dedicated
 * middleware can wrap and record regardless of outcome."). It wraps the entire
 * pipeline and writes exactly one row on both success and failure, then
 * re-throws the original exception untouched.
 *
 * Pipeline ordering — Telemetry sits OUTSIDE Cache so the measured latency
 * includes the cache lookup and a cache-served response still produces a row:
 *
 *   TelemetryMiddleware        <-- outermost; pure observer          (priority 110)
 *     IdempotencyMiddleware    <-- replays a stored result by key    (priority 105)
 *       CacheMiddleware        <-- short-circuits on hit             (priority 100)
 *         GuardrailMiddleware  <-- screens/redacts the response      (priority 90)
 *           BudgetMiddleware   <-- pre-flight denial                 (priority 75)
 *             FallbackMiddleware <-- swaps config on retryable failure (priority 50)
 *               UsageMiddleware  <-- records the call that ran       (priority 25)
 *                 CircuitBreaker <-- guards the provider call        (priority 20)
 *                   <terminal>
 *
 * The guardrail sits INSIDE this layer, so a guardrail policy outcome
 * ({@see GuardrailPolicyException}) is recorded as a SUCCESSFUL provider run (the
 * provider produced a response; the guardrail then blocked it) — not a provider
 * failure. A genuine provider/budget exception still records success=false.
 *
 * What it records: the OPERATION, the requested primary CONFIGURATION
 * (identifier, plus its provider/model) AND the one that actually served the
 * run (`served_*`). The two are equal unless FallbackMiddleware swapped to a
 * sibling that then answered — which it signals through the scratchpad on the
 * context, the only channel that survives the pipeline unwind (see
 * {@see TelemetrySignals}). `fallback_attempts` still counts the hops; it
 * cannot say which configuration answered, and a chain that was exhausted
 * without anyone answering has hops but no swap. Ad-hoc direct calls carry no
 * attached model, so provider/model are empty and the provider is encoded in
 * the `ad-hoc:<operation>:<provider>` identifier.
 *
 * Privacy: no prompt, no response, no exception message — only the exception
 * FQCN (`error_class`), because messages can carry payload fragments. The
 * central privacy model (retention tiers) is a later workstream; this
 * middleware is metadata-only by construction.
 *
 * Fail-soft: a telemetry write error is logged and swallowed. Observability
 * must never break the call it observes.
 *
 * Streaming stays out of the pipeline (ADR-026), so streamed responses produce
 * no telemetry row here; a streaming lifecycle is a separate workstream.
 *
 * Disable via the `telemetry.enabled` extension setting (default ON). When
 * disabled the middleware is a verbatim pass-through.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME)]
final readonly class TelemetryMiddleware implements ProviderMiddlewareInterface
{
    /**
     * Pipeline priority, read by the tagged iterator via
     * `defaultPriorityMethod` (ADR-085 ordering).
     *
     * It lives in code rather than in the AutoconfigureTag attribute
     * because an attribute priority is lost when the same tag is
     * declared on both the interface and the class and the container
     * deduplicates the two — which silently unsorts the whole pipeline.
     */
    public static function getDefaultPriority(): int
    {
        return 110;
    }

    public function __construct(
        private TelemetryRepositoryInterface $repository,
        private Context $context,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
        private ProviderRetryCounter $retryCounter = new ProviderRetryCounter(),
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        if (!$this->isEnabled()) {
            return $next($context);
        }

        $start      = hrtime(true);
        $success    = false;
        $errorClass = '';
        // Snapshot difference rather than a reset (ADR-174): a tool loop nests
        // pipeline runs, and a reset by an inner run would make this row report
        // only the retries that happened after it finished. Taken here, on the
        // outermost layer, so the count covers the fallback re-sends too.
        //
        // ONE difference is exact here because the run below is synchronous:
        // the pipeline holds the stack from this line to the finally, so no
        // caller code runs in between. The streaming path does not go through
        // the pipeline and is a generator, which is why StreamingDispatcher
        // accumulates per resumption segment instead.
        $retriesBefore = $this->retryCounter->total();

        try {
            $result  = $next($context);
            $success = true;

            return $result;
        } catch (GuardrailPolicyException $e) {
            // A guardrail policy outcome (block / approval-required) means the
            // provider call itself SUCCEEDED — the guardrail (inside this layer,
            // priority 90) simply refused to release the response. Record it as a
            // successful run (errorClass stays '') so a guardrail denial never
            // distorts the provider failure-rate. Re-throw untouched.
            $success = true;

            throw $e;
        } catch (Throwable $e) {
            $errorClass = $e::class;

            throw $e;
        } finally {
            $this->safeRecord(
                $context,
                $success,
                $errorClass,
                $this->elapsedMs($start),
                $this->retryCounter->total() - $retriesBefore,
            );
        }
    }

    /**
     * Wall-clock milliseconds since the given hrtime() reading. Integer ms is
     * enough resolution for latency buckets and keeps the column an int.
     */
    private function elapsedMs(int|float $startNs): int
    {
        return (int)((hrtime(true) - $startNs) / 1_000_000);
    }

    private function safeRecord(
        ProviderCallContext $context,
        bool $success,
        string $errorClass,
        int $latencyMs,
        int $providerRetries,
    ): void {
        try {
            $signals = $context->telemetrySignals;

            $this->repository->record(new TelemetryRecord(
                correlationId: $context->correlationId,
                operation: $context->operation->value,
                provider: $context->telemetryProvider(),
                model: $context->telemetryModel(),
                configurationIdentifier: $context->telemetryConfigurationIdentifier(),
                beUser: $this->resolveBeUser($context),
                success: $success,
                errorClass: $errorClass,
                latencyMs: $latencyMs,
                cacheHit: $signals->cacheHit,
                fallbackAttempts: $signals->fallbackAttempts,
                // No swap recorded ⇒ the requested configuration is the one that
                // served (or nothing did, on a failed run). Writing the requested
                // triple rather than leaving the columns empty keeps every row
                // self-describing: "which configuration answered this call" is one
                // column, never a COALESCE over two.
                servedConfigurationIdentifier: $signals->servedConfigurationIdentifier ?? $context->telemetryConfigurationIdentifier(),
                servedProvider: $signals->servedProvider ?? $context->telemetryProvider(),
                servedModel: $signals->servedModel ?? $context->telemetryModel(),
                // Both null unless something recorded them on the way in: the
                // routing summary comes from a criteria-mode resolution
                // (ADR-156), the complexity from the send's context fit. Null
                // stays null — this layer has no payload to measure and no
                // decision to reconstruct, so it can only pass on what the
                // inner layers found.
                routingSummary: $signals->routingSummary,
                complexity: $signals->complexity,
                // The two halves of ADR-174, from the same scratchpad: the
                // facts were recorded on the way in, before anything resolved a
                // model, and the usage on the way out, by UsageMiddleware. Null
                // stays null on both — a cache hit and a failed run reach here
                // with no usage, and neither spent a token.
                requestFacts: $signals->requestFacts,
                callUsage: $signals->callUsage,
                providerRetries: $providerRetries,
            ));
        } catch (Throwable $e) {
            // Observability must not break the call it observes. safeRecord()
            // runs inside handle()'s finally, so a Throwable escaping here would
            // (per PHP finally semantics) replace the provider exception the
            // caller is meant to see. The logger itself can throw (e.g. TYPO3's
            // FileWriter on a full/read-only var/log), so the log call is
            // guarded too — a logging failure is swallowed as a last resort.
            try {
                $this->logger->error(
                    'Failed to record LLM telemetry',
                    [
                        'correlationId' => $context->correlationId,
                        'operation'     => $context->operation->value,
                        'exception'     => $e,
                    ],
                );
            } catch (Throwable) {
                // Nothing safe left to do; never let observability break the call.
            }
        }
    }

    /**
     * Attribution: the caller-supplied backend user (BudgetMiddleware reads the
     * same key for enforcement), else the ambient backend.user aspect, else 0
     * (CLI / scheduler / unauthenticated) — mirroring UsageTrackerService.
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
     * Telemetry defaults ON (observability by default). Any read failure or a
     * missing setting is treated as enabled; only an explicit falsey
     * `telemetry.enabled` turns it off.
     */
    private function isEnabled(): bool
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

        // Extension configuration delivers booleans as '0' / '1' strings;
        // (bool) maps '0' and '' to false, everything else to true.
        return (bool)$telemetry['enabled'];
    }
}
