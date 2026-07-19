<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
    ) {}

    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $result = $next($configuration);
        if ($result instanceof CompletionResponse) {
            return $this->screen($result, $configuration, $next, false);
        }
        if ($result instanceof VisionResponse) {
            return $this->screenVision($result);
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
    private function screenVision(VisionResponse $vision): VisionResponse
    {
        $screened = $vision->description;
        foreach ($this->guardrails as $guardrail) {
            $result  = $guardrail->checkOutput(new CompletionResponse($screened, $vision->model, $vision->usage, provider: $vision->provider));
            $verdict = $result->verdict;
            if ($verdict === GuardrailVerdict::RETRY) {
                throw new GuardrailViolationException(
                    $guardrail::class,
                    'A guardrail asked to retry, but retrying is not supported for vision responses.',
                );
            }
            $screened = match ($verdict) {
                GuardrailVerdict::ALLOW => $screened,
                GuardrailVerdict::REDACT => $result->redactedContent ?? $screened,
                GuardrailVerdict::DENY => throw new GuardrailViolationException(
                    $guardrail::class,
                    $result->reason !== '' ? $result->reason : 'A guardrail denied the response.',
                ),
                GuardrailVerdict::REQUIRE_APPROVAL => throw new GuardrailApprovalRequiredException(
                    $guardrail::class,
                    $result->reason !== '' ? $result->reason : 'A guardrail flagged the response for human approval.',
                ),
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
     * @param callable(LlmConfiguration): mixed $next
     */
    private function screen(
        CompletionResponse $response,
        LlmConfiguration $configuration,
        callable $next,
        bool $retried,
    ): CompletionResponse {
        foreach ($this->guardrails as $guardrail) {
            $result  = $guardrail->checkOutput($response);
            $verdict = $result->verdict;

            // RETRY short-circuits the loop (it re-runs the pipeline and re-screens
            // the fresh response from scratch), so it is handled before the match.
            if ($verdict === GuardrailVerdict::RETRY) {
                return $this->retryOnce($response, $configuration, $next, $retried, $guardrail, $result);
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
                GuardrailVerdict::DENY => throw new GuardrailViolationException(
                    $guardrail::class,
                    $result->reason !== '' ? $result->reason : 'A guardrail denied the response.',
                ),
                GuardrailVerdict::REQUIRE_APPROVAL => throw new GuardrailApprovalRequiredException(
                    $guardrail::class,
                    $result->reason !== '' ? $result->reason : 'A guardrail flagged the response for human approval.',
                ),
            };
        }

        return $response;
    }

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    private function retryOnce(
        CompletionResponse $response,
        LlmConfiguration $configuration,
        callable $next,
        bool $retried,
        GuardrailInterface $guardrail,
        GuardrailResult $result,
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
        $fresh = $next($configuration);
        if (!$fresh instanceof CompletionResponse) {
            return $response;
        }

        return $this->screen($fresh, $configuration, $next, true);
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
}
