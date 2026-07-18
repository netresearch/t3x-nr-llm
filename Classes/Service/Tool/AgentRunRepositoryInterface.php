<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;

/**
 * Persistence contract for agent runs and their event streams (ADR-081).
 *
 * A UI-less append-and-update log, mirroring {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface}:
 * raw Doctrine access, no Extbase. Split out as an interface so the persister
 * can be unit-tested against a double.
 */
interface AgentRunRepositoryInterface
{
    /**
     * Insert a new run in the RUNNING state and return its primary key.
     */
    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int;

    /**
     * Append one event to a run's stream.
     */
    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void;

    /**
     * Move a run into a terminal state, recording its final totals.
     */
    public function finishRun(
        int $runUid,
        string $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
    ): void;

    public function findByUuid(string $uuid): ?AgentRun;

    /**
     * @return list<AgentRunEvent> Events for a run, ordered by sequence ascending.
     */
    public function findEvents(int $runUid): array;

    /**
     * Delete runs (and their events) created before the given timestamp.
     *
     * @return int Number of run rows deleted.
     */
    public function purgeOlderThan(int $timestamp): int;
}
