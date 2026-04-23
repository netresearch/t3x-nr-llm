<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;

/**
 * Records usage to tx_nrllm_service_usage after a successful provider call.
 *
 * Counterpart of BudgetMiddleware. Budget is a pre-flight gate; this
 * middleware is the post-flight recorder that feeds the same table
 * BudgetService aggregates from, so the two stay consistent without a
 * second source of truth.
 *
 * Recognised response types:
 *  - CompletionResponse
 *  - EmbeddingResponse
 *  - VisionResponse
 *
 * All three carry a typed UsageStatistics + provider identifier. Any
 * other return value (streaming Generator, translation result, raw
 * string, plain array) is silently skipped — there is nothing reliable
 * to record for those shapes from this position in the pipeline.
 *
 * Pipeline ordering recommendation:
 *
 *   BudgetMiddleware         <-- outermost; pre-flight denial
 *     FallbackMiddleware     <-- swaps LlmConfiguration on retryable failure
 *       UsageMiddleware      <-- inner; sees the config that actually ran
 *         <terminal provider call>
 *
 * With this order the $configuration parameter reaching UsageMiddleware
 * is the one FallbackMiddleware actually dispatched (the fallback, if
 * any swap happened) — so the recorded configuration_uid reflects
 * reality, not the primary that failed. The response's $provider field
 * is used when present; otherwise the middleware records 'unknown'.
 *
 * The middleware never runs when $next throws: failed calls are not
 * tracked here. If failure-rate telemetry is needed later, a dedicated
 * middleware can wrap and record regardless of outcome.
 */
final readonly class UsageMiddleware implements ProviderMiddlewareInterface
{
    public function __construct(
        private UsageTrackerServiceInterface $usageTracker,
    ) {}

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $result = $next($configuration);

        $this->track($context, $configuration, $result);

        return $result;
    }

    private function track(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        mixed $result,
    ): void {
        if (
            !$result instanceof CompletionResponse
            && !$result instanceof EmbeddingResponse
            && !$result instanceof VisionResponse
        ) {
            return;
        }

        $usage = $result->usage;

        /** @var array{tokens?: int, cost?: float} $metrics */
        $metrics = [
            'tokens' => $usage->totalTokens,
        ];
        if ($usage->estimatedCost !== null) {
            $metrics['cost'] = $usage->estimatedCost;
        }

        $provider = $result->provider !== '' ? $result->provider : 'unknown';

        $configUid = $configuration->getUid();
        $uid       = ($configUid !== null && $configUid > 0) ? $configUid : null;

        $this->usageTracker->trackUsage(
            serviceType: $context->operation->value,
            provider: $provider,
            metrics: $metrics,
            configurationUid: $uid,
        );
    }
}
