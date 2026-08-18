<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Option\AbstractOptions;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Builds the pipeline metadata the middlewares read, extracted verbatim from
 * LlmServiceManager (ADR-059 stage 2).
 *
 * Five producers, five disjoint key sets — budget, idempotency, request
 * counting, caller source, agent run — which is why every call site merges
 * them with `+` and must keep doing so: the disjointness is load-bearing, and
 * array_merge would let a later set silently win over an earlier one if a key
 * ever collided.
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

    /**
     * Translate the caller identity (ADR-177) into the metadata keys the
     * TelemetryMiddleware persists as source_extension / source_operation.
     * An unannotated call produces no entry, so its telemetry row keeps the
     * '' defaults and stays indistinguishable from a pre-feature row.
     * Disjoint from every other producer's keys, so it merges with `+`.
     *
     * @return array<string, string>
     */
    public function callerSource(AbstractOptions $options): array
    {
        $extension = $options->getCallerSourceExtension();
        if ($extension === null || $extension === '') {
            return [];
        }

        return [
            TelemetryMiddleware::METADATA_SOURCE_EXTENSION => $extension,
            TelemetryMiddleware::METADATA_SOURCE_OPERATION => $options->getCallerSourceOperation() ?? '',
        ];
    }

    /**
     * Translate the agent run driving this call (ADR-153) into the metadata key
     * {@see GuardrailMiddleware} stamps onto its governance rows. No run — a
     * plain provider call — produces no entry, so the middleware's 0 default
     * stands and means "outside a run" rather than "identity lost".
     *
     * An unpersisted run (uid 0) produces no entry either: there is no row for a
     * governance event to point at.
     *
     * @return array<string, mixed>
     */
    public function agentRun(?AgentRunReference $run): array
    {
        if (!$run instanceof AgentRunReference || $run->uid <= 0) {
            return [];
        }

        return [GuardrailMiddleware::METADATA_AGENT_RUN_UID => $run->uid];
    }
}
