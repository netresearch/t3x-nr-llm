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
        // Serialised AgentRunRequest JSON from enqueue() (ADR-102); kept while
        // the run is queued or executing (a requeue can reuse it) and cleared
        // by the guarded terminal settle, like suspended_state.
        public ?string $queuedRequest = null,
        // Worker lease (ADR-102): who claimed the queued run and until when the
        // claim is presumed live; ''/0 = not claimed.
        public string $claimedBy = '',
        public int $leaseExpires = 0,
        // How many times the run has been requeued (ADR-104), by either a
        // retryable-failure retry or a stale-lease reclaim; capped by
        // AgentRuntime::MAX_REQUEUES so a deterministic crash cannot loop.
        public int $requeueCount = 0,
    ) {}

    /**
     * A copy without the raw payload carriers, for status exposure (ADR-101).
     * The suspended state and the queued request are stored VERBATIM for
     * resume/execution — they bypass the
     * {@see \Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter} that every
     * persisted event goes through (ADR-064) — so a status projection handed to
     * a runtime consumer must not carry them.
     */
    public function withoutSuspendedState(): self
    {
        return new self(
            uid: $this->uid,
            uuid: $this->uuid,
            status: $this->status,
            configurationUid: $this->configurationUid,
            configurationIdentifier: $this->configurationIdentifier,
            beUser: $this->beUser,
            iterations: $this->iterations,
            truncated: $this->truncated,
            totalPromptTokens: $this->totalPromptTokens,
            totalCompletionTokens: $this->totalCompletionTokens,
            totalTokens: $this->totalTokens,
            estimatedCost: $this->estimatedCost,
            errorClass: $this->errorClass,
            terminationReason: $this->terminationReason,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            crdate: $this->crdate,
            suspendedState: null,
            queuedRequest: null,
            claimedBy: $this->claimedBy,
            leaseExpires: $this->leaseExpires,
            requeueCount: $this->requeueCount,
        );
    }

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
