<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Exception\OutputBudgetExhaustedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

use function trim;

/**
 * Turns an answer that ran out of output tokens before its first word into a
 * typed error (ADR-241).
 *
 * Below the guardrails, which have nothing to screen in it, and above the cache
 * layers, so no empty answer is stored. It sits above the fallback chain on
 * purpose: the provider did answer, so the circuit breaker sees a success, and a
 * sibling configuration is not tried for a limit a person has to raise.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME)]
final readonly class EmptyAnswerMiddleware implements ProviderMiddlewareInterface
{
    /**
     * See {@see GuardrailMiddleware::getDefaultPriority()} for why this lives in
     * code.
     */
    public static function getDefaultPriority(): int
    {
        return 85;
    }

    public function handle(ProviderCallContext $context, callable $next): mixed
    {
        $result = $next($context);

        if ($result instanceof CompletionResponse
            && $result->wasTruncated()
            && !$result->hasToolCalls()
            && trim($result->content) === ''
        ) {
            throw new OutputBudgetExhaustedException(
                $context->configuration?->getIdentifier() ?? '',
                $result->usage->completionTokens,
            );
        }

        return $result;
    }
}
