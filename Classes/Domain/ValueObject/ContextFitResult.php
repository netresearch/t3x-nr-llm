<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The outcome of fitting an agent-loop transcript into the model's context
 * window (ADR-107).
 *
 * {@see messages} is the (possibly pruned) message list to send — always a
 * structurally valid, tool-call/tool-result-paired transcript with the leading
 * system/task messages and the newest turn preserved. {@see overflowAtFloor} is
 * true when even that floor still exceeds the budget: the caller must NOT send
 * it (a provider 4xx), and stops the run on
 * {@see \Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason::CONTEXT_TRUNCATED}.
 */
final readonly class ContextFitResult
{
    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function __construct(
        public array $messages,
        public bool $pruned,
        public int $droppedTurns,
        public int $keptTurns,
        public int $estimatedTokens,
        public int $budget,
        public bool $overflowAtFloor,
        public float $calibration,
    ) {}
}
