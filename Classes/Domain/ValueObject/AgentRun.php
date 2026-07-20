<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;

/**
 * A persisted agent run, read back from `tx_nrllm_agentrun` (ADR-081).
 *
 * The immutable read model of one run of {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}.
 * The mutable in-flight counterpart is {@see \Netresearch\NrLlm\Service\Tool\AgentRunHandle},
 * which the persister threads through a run before this read model exists.
 */
final readonly class AgentRun
{
    public function __construct(
        public int $uid,
        public string $uuid,
        public string $status,
        public int $configurationUid,
        public string $configurationIdentifier,
        public int $beUser,
        public int $iterations,
        public bool $truncated,
        public int $totalPromptTokens,
        public int $totalCompletionTokens,
        public int $totalTokens,
        public float $estimatedCost,
        public string $errorClass,
        public string $terminationReason,
        public int $startedAt,
        public int $finishedAt,
        public int $crdate,
        // Serialised SuspendedRunState JSON while status = waiting_for_approval
        // (ADR-084); null once the run is running or terminal.
        public ?string $suspendedState = null,
    ) {}

    /**
     * The status as a typed enum, or null when the stored string is unknown
     * (a forward-compatibility guard — an unrecognised status is not coerced).
     */
    public function statusEnum(): ?AgentRunStatus
    {
        return AgentRunStatus::tryFromString($this->status);
    }

    /**
     * Why the run ended, as a typed enum; null while it is still going or when
     * the stored value is unknown (forward compatibility, same guard as
     * {@see self::statusEnum()}).
     */
    public function terminationReasonEnum(): ?AgentRunTerminationReason
    {
        return AgentRunTerminationReason::tryFromString($this->terminationReason);
    }
}
