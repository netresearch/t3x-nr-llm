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
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Applies the guardrail collection to every non-streaming provider response
 * (ADR-085).
 *
 * Runs outermost of the enforcement middlewares (priority 115, above Telemetry's
 * 110) so a guardrail sees the final response returned to the caller. After the
 * downstream chain produces a {@see CompletionResponse}, each tagged
 * {@see GuardrailInterface} is asked for a verdict, in tag order:
 * - ALLOW: pass on;
 * - REDACT: replace the content with the guardrail's version, and keep screening
 *   (a later guardrail may still deny);
 * - DENY: throw {@see GuardrailViolationException};
 * - REQUIRE_APPROVAL: throw {@see GuardrailApprovalRequiredException};
 * - RETRY: ask the provider once more and re-screen the fresh response (capped at
 *   one retry).
 *
 * Non-`CompletionResponse` operations (embeddings, vision, raw arrays) pass
 * through untouched. Streaming bypasses the whole pipeline (see ADR-085 /
 * ADR-062), so streamed output is not screened here.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 115])]
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
        if (!$result instanceof CompletionResponse) {
            return $result;
        }

        return $this->screen($result, $configuration, $next, false);
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
            $result = $guardrail->checkOutput($response);
            switch ($result->verdict) {
                case GuardrailVerdict::ALLOW:
                    break;
                case GuardrailVerdict::REDACT:
                    $response = $this->withContent($response, $result->redactedContent ?? $response->content, $result->redactedThinking);
                    break;
                case GuardrailVerdict::DENY:
                    throw new GuardrailViolationException(
                        $guardrail::class,
                        $result->reason !== '' ? $result->reason : 'A guardrail denied the response.',
                    );
                case GuardrailVerdict::REQUIRE_APPROVAL:
                    throw new GuardrailApprovalRequiredException(
                        $guardrail::class,
                        $result->reason !== '' ? $result->reason : 'A guardrail flagged the response for human approval.',
                    );
                case GuardrailVerdict::RETRY:
                    if ($retried) {
                        throw new GuardrailViolationException(
                            $guardrail::class,
                            'A guardrail asked to retry, but the retried response also failed: ' . $result->reason,
                        );
                    }
                    $fresh = $next($configuration);
                    if (!$fresh instanceof CompletionResponse) {
                        return $response;
                    }

                    return $this->screen($fresh, $configuration, $next, true);
            }
        }

        return $response;
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
