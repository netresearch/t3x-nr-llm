<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;

/**
 * One persisted event of an agent run, read back from `tx_nrllm_agentrun_event`
 * (ADR-081).
 *
 * Each event is the durable form of a {@see RunStep}: the replayable stream a
 * consumer UI can render after the fact. The full step payload is preserved in
 * {@see self::$payload} (the {@see RunStep::toArray()} shape); the promoted
 * columns (kind, round, duration) exist for querying and ordering.
 */
final readonly class AgentRunEvent
{
    /**
     * @param array<string, mixed> $payload The decoded {@see RunStep::toArray()} snapshot.
     */
    public function __construct(
        public int $uid,
        public int $run,
        public int $sequence,
        public string $kind,
        public int $round,
        public float $durationMs,
        public array $payload,
        public int $crdate,
    ) {}

    /**
     * The kind as a typed enum, or null when the stored string is unknown.
     */
    public function kindEnum(): ?AgentEventKind
    {
        return AgentEventKind::fromRunStepKind($this->kind);
    }
}
