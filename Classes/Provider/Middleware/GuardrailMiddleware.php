<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailPolicyResolver;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Applies the guardrail collection to every non-streaming provider response
 * (ADR-085).
 *
 * Runs at priority 90 — INSIDE both persistence layers (IdempotencyMiddleware
 * 105, CacheMiddleware 100) and above the behavioural stack (Budget 75 down).
 * This placement is load-bearing for privacy: on the pipeline unwind the
 * guardrail redacts (or blocks) the response BEFORE Idempotency serialises it,
 * so no unredacted content is ever persisted to the nrllm_idempotency cache, and
 * a DENY/REQUIRE_APPROVAL throws before that store runs, so a blocked response is
 * never stored as a replayable result. The guardrail now sits inside Telemetry
 * (110); TelemetryMiddleware classifies a {@see GuardrailPolicyException} as a
 * successful provider run, so a guardrail policy outcome is still not counted as
 * a provider failure. After the downstream chain produces a
 * {@see CompletionResponse}, each tagged {@see GuardrailInterface} is asked for a
 * verdict, in tag order:
 * - ALLOW: pass on;
 * - REDACT: replace the content with the guardrail's version, and keep screening
 *   (a later guardrail may still deny);
 * - DENY: throw {@see GuardrailViolationException};
 * - REQUIRE_APPROVAL: throw {@see GuardrailApprovalRequiredException};
 * - RETRY: ask the provider once more and re-screen the fresh response (capped at
 *   one retry).
 *
 * A {@see VisionResponse}'s description is screened too (it is model-generated,
 * untrusted text — a secret in a transcribed image would otherwise leak).
 * Embeddings and raw-array operations pass through untouched. Streaming bypasses
 * the whole pipeline (see ADR-085 / ADR-062), so streamed output is not screened
 * here.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 90])]
final readonly class GuardrailMiddleware implements ProviderMiddlewareInterface
{
    /**
     * @param iterable<GuardrailInterface> $guardrails
     */
    public function __construct(
        #[AutowireIterator(GuardrailInterface::TAG_NAME)]
        private iterable $guardrails,
        // Autowired in production; the no-op default keeps the lean test wiring
        // working (a null/empty selection runs all guardrails, unchanged from
        // before ADR-106).
        private GuardrailPolicyResolver $policyResolver = new GuardrailPolicyResolver(new GuardrailRegistry([], [])),
        // Records a DENY / REQUIRE_APPROVAL / content-filter outcome so it becomes
        // queryable (governance-blocks widget). Nullable to preserve the lean test
        // wiring, like the $policyResolver default above; absent it the verdict is
        // still enforced, it just is not persisted.
        private ?GovernanceEventRepositoryInterface $governanceEvents = null,
    ) {}

    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        $result = $next($context);
        // Narrow the global collection to the configuration's policy ONCE per
        // call (ADR-106); the mandatory floor is preserved regardless. The
        // configuration already rides on the context (ADR-096) — no re-plumbing.
        $guardrails = $this->policyResolver->filter($this->guardrails, $context->configuration);
        if ($result instanceof CompletionResponse) {
            return $this->screen($result, $context, $next, false, $guardrails);
        }
        if ($result instanceof VisionResponse) {
            return $this->screenVision($result, $context, $guardrails);
        }

        return $result;
    }

    /**
     * Screen a vision response's description text through the same output
     * guardrails. REDACT rewrites the description, DENY/REQUIRE_APPROVAL throw the
     * same exceptions as a completion. A vision call cannot be re-requested
     * through the pipeline, so a RETRY (the response was deemed deficient) fails
     * CLOSED — throwing rather than silently returning the unscreened response.
     */
    /**
     * @param list<GuardrailInterface> $guardrails the config-filtered output guardrails
     */
    private function screenVision(VisionResponse $vision, ProviderCallContext $context, array $guardrails): VisionResponse
    {
        $screened = $vision->description;
        foreach ($guardrails as $guardrail) {
            $candidate = new CompletionResponse($screened, $vision->model, $vision->usage, provider: $vision->provider);
            $result    = $guardrail->checkOutput($candidate);
            $verdict   = $result->verdict;
            if ($verdict === GuardrailVerdict::RETRY) {
                throw new GuardrailViolationException(
                    $guardrail::class,
                    'A guardrail asked to retry, but retrying is not supported for vision responses.',
                );
            }
            $screened = match ($verdict) {
                GuardrailVerdict::ALLOW => $screened,
                GuardrailVerdict::REDACT => $result->redactedContent ?? $screened,
                GuardrailVerdict::DENY,
                GuardrailVerdict::REQUIRE_APPROVAL => $this->recordAndThrow($verdict, $guardrail, $result, $candidate, $context),
            };
        }

        if ($screened === $vision->description) {
            return $vision;
        }

        return new VisionResponse(
            description: $screened,
            model: $vision->model,
            usage: $vision->usage,
            provider: $vision->provider,
            confidence: $vision->confidence,
            detectedObjects: $vision->detectedObjects,
            metadata: $vision->metadata,
        );
    }

    /**
     * @param callable(ProviderCallContext): mixed $next
     * @param list<GuardrailInterface>             $guardrails the config-filtered output guardrails
     */
    private function screen(
        CompletionResponse $response,
        ProviderCallContext $context,
        callable $next,
        bool $retried,
        array $guardrails,
    ): CompletionResponse {
        foreach ($guardrails as $guardrail) {
            $result  = $guardrail->checkOutput($response);
            $verdict = $result->verdict;

            // RETRY short-circuits the loop (it re-runs the pipeline and re-screens
            // the fresh response from scratch), so it is handled before the match.
            if ($verdict === GuardrailVerdict::RETRY) {
                return $this->retryOnce($response, $context, $next, $retried, $guardrail, $result, $guardrails);
            }

            // match (no default) is exhaustive over the remaining verdicts: a new
            // verdict left unhandled is a PHPStan error / runtime UnhandledMatchError
            // (fail closed), never a silent pass-through of an unscreened response.
            $response = match ($verdict) {
                GuardrailVerdict::ALLOW => $response,
                GuardrailVerdict::REDACT => $this->withContent(
                    $response,
                    $result->redactedContent ?? $response->content,
                    $result->redactedThinking,
                ),
                GuardrailVerdict::DENY,
                GuardrailVerdict::REQUIRE_APPROVAL => $this->recordAndThrow($verdict, $guardrail, $result, $response, $context),
            };
        }

        return $response;
    }

    /**
     * @param callable(ProviderCallContext): mixed $next
     * @param list<GuardrailInterface>             $guardrails the config-filtered output guardrails
     */
    private function retryOnce(
        CompletionResponse $response,
        ProviderCallContext $context,
        callable $next,
        bool $retried,
        GuardrailInterface $guardrail,
        GuardrailResult $result,
        array $guardrails,
    ): CompletionResponse {
        if ($retried) {
            throw new GuardrailViolationException(
                $guardrail::class,
                'A guardrail asked to retry, but the retried response also failed: ' . $result->reason,
            );
        }
        // $next is the behavioural stack BELOW this guardrail (Budget → Fallback →
        // Usage → CircuitBreaker → terminal) and INSIDE Idempotency/Cache, so a
        // retry genuinely re-runs the provider rather than replaying an
        // idempotency-cached response. Still capped at one retry; no shipped
        // guardrail returns RETRY.
        $fresh = $next($context);
        if (!$fresh instanceof CompletionResponse) {
            return $response;
        }

        return $this->screen($fresh, $context, $next, true, $guardrails);
    }

    private function withContent(CompletionResponse $response, string $content, ?string $thinking): CompletionResponse
    {
        // CompletionResponse is final readonly — rebuild it with the new content
        // (and redacted thinking, if the guardrail supplied one; null keeps it).
        return new CompletionResponse(
            content: $content,
            model: $response->model,
            usage: $response->usage,
            finishReason: $response->finishReason,
            provider: $response->provider,
            toolCalls: $response->toolCalls,
            metadata: $response->metadata,
            thinking: $thinking ?? $response->thinking,
        );
    }

    /**
     * Persist the governance event for a blocking verdict, then throw the
     * matching exception — one helper shared by the DENY / REQUIRE_APPROVAL arms
     * of {@see screen()} and {@see screenVision()} so the record()+throw is not
     * duplicated four times. Recorded before throwing so a blocked response is
     * captured even though the pipeline unwinds. Only class names and policy
     * facts are stored — never the response content (ADR-064).
     *
     * A DENY on a provider-flagged response (finishReason = content_filter) is
     * tagged CONTENT_FILTER so a provider-side safety stop is separately
     * measurable; REQUIRE_APPROVAL is always APPROVAL_REQUIRED.
     */
    private function recordAndThrow(
        GuardrailVerdict $verdict,
        GuardrailInterface $guardrail,
        GuardrailResult $result,
        CompletionResponse $response,
        ProviderCallContext $context,
    ): never {
        $decision = match (true) {
            $verdict === GuardrailVerdict::REQUIRE_APPROVAL => GovernanceDecision::APPROVAL_REQUIRED,
            $response->wasFiltered()                        => GovernanceDecision::CONTENT_FILTER,
            default                                         => GovernanceDecision::RESPONSE_BLOCKED,
        };
        $reason = match ($decision) {
            GovernanceDecision::APPROVAL_REQUIRED => GuardrailVerdict::REQUIRE_APPROVAL->value,
            GovernanceDecision::CONTENT_FILTER    => GovernanceDecision::CONTENT_FILTER->value,
            default                               => GuardrailVerdict::DENY->value,
        };

        $this->governanceEvents?->record(new GovernanceEvent(
            correlationId: $context->correlationId,
            decision: $decision->value,
            reason: $reason,
            provider: $response->provider !== '' ? $response->provider : $context->telemetryProvider(),
            model: $response->model !== '' ? $response->model : $context->telemetryModel(),
            configurationIdentifier: $context->telemetryConfigurationIdentifier(),
            beUser: $this->resolveBeUser($context),
            toolName: '',
            // The middleware runs below the run identity, so the run uid is not
            // known here; correlation_id is the join key to the run instead.
            agentrunUid: 0,
            guardrail: $guardrail::class,
            // The guardrail's policy reason — a policy fact, never response content.
            detail: $result->reason,
        ));

        if ($verdict === GuardrailVerdict::REQUIRE_APPROVAL) {
            throw new GuardrailApprovalRequiredException(
                $guardrail::class,
                $result->reason !== '' ? $result->reason : 'A guardrail flagged the response for human approval.',
            );
        }

        throw new GuardrailViolationException(
            $guardrail::class,
            $result->reason !== '' ? $result->reason : 'A guardrail denied the response.',
        );
    }

    /**
     * The acting backend user uid, mirroring TelemetryMiddleware's resolution:
     * the explicit metadata uid the runtime threads through (ADR-083), else the
     * ambient backend user, else 0 (CLI / scheduler / unauthenticated).
     */
    private function resolveBeUser(ProviderCallContext $context): int
    {
        $fromMetadata = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null;
        if (\is_int($fromMetadata)) {
            return $fromMetadata;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && \is_array($backendUser->user)) {
            $uid = $backendUser->user['uid'] ?? null;

            return \is_numeric($uid) ? (int)$uid : 0;
        }

        return 0;
    }
}
