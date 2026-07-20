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
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * Records usage to tx_nrllm_service_usage after a successful provider call.
 *
 * Counterpart of BudgetMiddleware. Budget is a pre-flight gate; this
 * middleware is the post-flight recorder that feeds the same table
 * BudgetService aggregates from, so the two stay consistent without a
 * second source of truth.
 *
 * Recognised response shapes:
 *  - CompletionResponse / EmbeddingResponse / VisionResponse (typed path)
 *  - `array{usage: array, provider: string, ...}` (array payload emitted by
 *    feature services that opt in to CacheMiddleware — CacheMiddleware
 *    stores `array<string, mixed>`, so the terminal is wrapped with a
 *    `$response->toArray()` codec, and UsageMiddleware records from the array
 *    shape on the cache-MISS path just as it does from a typed response.)
 *
 * A cache HIT is NOT recorded here: CacheMiddleware is the outermost layer
 * (priority 100) and short-circuits with the cached value before Budget/Usage
 * run, so a served-from-cache response is deliberately not re-billed.
 *
 * Streaming Generator, translation result, raw string, plain array without
 * `usage` / `provider` — silently skipped. Nothing reliable to record for
 * those shapes from this position in the pipeline.
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
 * tracked here. Failure-rate telemetry is handled by the dedicated
 * TelemetryMiddleware (ADR-058), which wraps the whole pipeline and records
 * one row regardless of outcome.
 *
 * Recording is fail-soft: a failure while writing to tx_nrllm_service_usage is
 * logged and swallowed, never re-thrown, so this post-flight bookkeeping cannot
 * turn an already-successful provider call into an error — nor be mis-recorded
 * by TelemetryMiddleware as a provider failure.
 *
 * The registered pipeline order, pinned by tag priority:
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
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 25])]
final readonly class UsageMiddleware implements ProviderMiddlewareInterface
{
    public const METADATA_TASK_UID = 'task_uid';

    public function __construct(
        private UsageTrackerServiceInterface $usageTracker,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        $result = $next($context);

        // Usage recording is post-flight bookkeeping. A tracker/DB failure here
        // must not fail an already-successful provider call, nor propagate out to
        // TelemetryMiddleware — which would otherwise record the served call as a
        // provider failure (success=false, the DB exception FQCN) and re-throw to
        // the caller. Fail soft, like every other accounting sink in the pipeline.
        try {
            $this->track($context, $result);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Usage tracking failed after a successful provider call; the call result is unaffected.',
                ['exception' => $e, 'operation' => $context->operation->value],
            );
        }

        return $result;
    }

    private function track(
        ProviderCallContext $context,
        mixed $result,
    ): void {
        [$usage, $provider, $responseModel] = $this->extractUsage($result);
        if ($usage === null) {
            return;
        }

        // A call with no configuration entity (a specialized service) carries no
        // model entity: the model id and cost then come from the response and
        // the context, not from a pricing lookup.
        $configuration = $context->configuration;
        $model         = $configuration?->getLlmModel();
        $modelUid      = $model?->getUid() ?? 0;
        // Ad-hoc/transient configurations (e.g. the embeddings path) carry no
        // model, so getModelId() is ''. Fall back to the model the provider
        // actually reported on the response so per-model analytics attribute
        // the usage instead of dropping it into an empty-model bucket.
        $modelId = $configuration?->getModelId() ?? $context->model;
        if ($modelId === '' && $responseModel !== '') {
            $modelId = $responseModel;
        }

        // Cost: prefer a cost the provider already computed; otherwise derive
        // it from the model's pricing and the prompt/completion token split.
        $cost = $usage->estimatedCost;
        if ($cost === null && $model !== null && $model->hasPricing()) {
            $cost = $model->estimateCost($usage->promptTokens, $usage->completionTokens);
        }

        /** @var array{tokens: int, promptTokens: int, completionTokens: int, cost?: float} $metrics */
        $metrics = [
            'tokens'           => $usage->totalTokens,
            'promptTokens'     => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
        ];
        if ($cost !== null) {
            $metrics['cost'] = $cost;
        }

        $configUid = $configuration?->getUid();
        $uid       = ($configUid !== null && $configUid > 0) ? $configUid : null;

        $taskUid = isset($context->metadata[self::METADATA_TASK_UID]) && is_int($context->metadata[self::METADATA_TASK_UID])
            ? $context->metadata[self::METADATA_TASK_UID]
            : 0;

        // The caller-supplied attribution uid (options `withBeUserUid()`,
        // forwarded by the manager as budget metadata). BudgetMiddleware reads
        // the same key for enforcement; recording it here keeps enforcement and
        // attribution consistent for callers that run outside a backend-user
        // request context (frontend plugins, CLI workers).
        $beUserUid = isset($context->metadata[BudgetMiddleware::METADATA_BE_USER_UID]) && is_int($context->metadata[BudgetMiddleware::METADATA_BE_USER_UID])
            ? $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID]
            : null;

        $this->usageTracker->trackUsage(
            serviceType: $context->operation->value,
            provider: $provider !== '' ? $provider : 'unknown',
            metrics: $metrics,
            configurationUid: $uid,
            modelUid: $modelUid,
            modelId: $modelId,
            taskUid: $taskUid,
            beUserUid: $beUserUid,
        );
    }

    /**
     * Extract `(usage, provider)` from whatever the terminal returned.
     *
     * Typed responses (common path) expose both fields directly. Array
     * payloads (CacheMiddleware codec shape) carry the same information
     * under `usage` / `provider` keys — the middleware reconstructs a
     * `UsageStatistics` from those so recording happens identically on
     * both paths. Unrecognised shapes return `[null, '', '']` so the
     * middleware silently skips.
     *
     * @return array{0: ?UsageStatistics, 1: string, 2: string}
     */
    private function extractUsage(mixed $result): array
    {
        if (
            $result instanceof CompletionResponse
            || $result instanceof EmbeddingResponse
            || $result instanceof VisionResponse
        ) {
            return [$result->usage, $result->provider, $result->model];
        }

        if (
            \is_array($result)
            && isset($result['usage'], $result['provider'])
            && \is_array($result['usage'])
            && \is_string($result['provider'])
        ) {
            /** @var array<string, mixed> $usageData */
            $usageData = $result['usage'];
            $model     = isset($result['model']) && \is_string($result['model']) ? $result['model'] : '';

            return [UsageStatistics::fromArray($usageData), $result['provider'], $model];
        }

        return [null, '', ''];
    }
}
