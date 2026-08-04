<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Builds the pipeline metadata the middlewares read, extracted verbatim from
 * LlmServiceManager (ADR-059 stage 2).
 *
 * Three producers, three disjoint key sets — budget, idempotency, request
 * counting — which is why every call site merges them with `+` and must keep
 * doing so: the disjointness is load-bearing, and array_merge would let a
 * later set silently win over an earlier one if a key ever collided.
 *
 * Stateless and pure; the manager holds one instance.
 */
final readonly class CallMetadataFactory
{
    /**
     * Translate the budget-relevant fields into the metadata keys the
     * BudgetMiddleware reads. Only non-null values become metadata —
     * the middleware's "skip the check" branch naturally fires for
     * absent keys, matching its documented contract (see
     * `BudgetMiddleware::handle()`).
     *
     * Takes raw nullable values rather than a typed option object so
     * every entry point can reuse it: `chat()` reads from `ChatOptions`,
     * `embed()` from `EmbeddingOptions`, `vision()` from `VisionOptions`,
     * `chatWithTools()` from `ToolOptions` — none of which share a
     * common base interface for these two fields. A small option-type-
     * agnostic helper is simpler than introducing a marker interface
     * just to thread two fields.
     *
     * Lives here rather than on the option objects so the
     * options layer does not need to know which middleware exists.
     *
     * @return array<string, mixed>
     */
    public function budget(?int $beUserUid, ?float $plannedCost): array
    {
        $metadata = [];

        if ($beUserUid !== null) {
            $metadata[BudgetMiddleware::METADATA_BE_USER_UID] = $beUserUid;
        }

        if ($plannedCost !== null) {
            $metadata[BudgetMiddleware::METADATA_PLANNED_COST] = $plannedCost;
        }

        return $metadata;
    }

    /**
     * Translate an optional idempotency key (ADR-063) into the metadata key the
     * IdempotencyMiddleware reads. Empty / absent keys produce no entry, so the
     * middleware's pass-through branch fires and non-idempotent calls are
     * untouched. Disjoint from {@see self::budget()} keys, so the
     * two merge with `+` at every call site.
     *
     * @return array<string, mixed>
     */
    public function idempotency(?string $idempotencyKey): array
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return [];
        }

        return [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $idempotencyKey];
    }

    /**
     * Pipeline metadata that tells UsageMiddleware to record the call's metrics
     * without incrementing the request counter. Set for chat sub-calls that
     * belong to a higher-level operation recording its own request row (e.g. a
     * translation's language-detection step, or the LLM translator's chat call).
     *
     * @return array<string, bool>
     */
    public function requestCount(ChatOptions $options): array
    {
        return $options->getSuppressRequestCount()
            ? [UsageMiddleware::METADATA_SKIP_REQUEST_COUNT => true]
            : [];
    }

}
