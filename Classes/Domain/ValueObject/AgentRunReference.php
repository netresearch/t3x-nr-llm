<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Which agent run a provider call belongs to (ADR-153).
 *
 * A run makes N provider calls, and every one of them used to mint its own
 * correlation id inside {@see \Netresearch\NrLlm\Provider\Middleware\ProviderCallContext},
 * so the N telemetry rows of one run had nothing in common. This reference is
 * threaded from the run down to the pipeline and answers both halves of the
 * attribution:
 *
 * - {@see self::correlationId()} — the run's uuid IS the correlation id of every
 *   call it makes. `tx_nrllm_agentrun.uuid` and `tx_nrllm_telemetry.correlation_id`
 *   are both an RFC 4122 uuid in a `varchar(36)`, so no column had to be added
 *   to carry it: the join key already existed on both sides.
 * - {@see self::$uid} — the run's primary key, which is what
 *   `tx_nrllm_governance_event.agentrun_uid` stores.
 *
 * Absent (null) wherever there is no run: a plain provider call, or a bare
 * {@see \Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface} consumer that
 * drives the loop without persistence. Those keep minting a per-call id, exactly
 * as before.
 *
 * - {@see self::$readToolNames} — set for a run that was offered tools: such a
 *   run stores what it produces through a tool, so its answer is conversation
 *   and only a writing tool's arguments are output (ADR-238). The list names
 *   the offered tools that declare a read. Null for a run that was given no
 *   tools, a queued task or a field fill: there the answer is the output.
 *
 * @api
 */
final readonly class AgentRunReference
{
    /**
     * @param list<string>|null $readToolNames
     */
    public function __construct(
        public int $uid,
        public string $uuid,
        public ?array $readToolNames = null,
    ) {}

    /**
     * This run as a run that offers tools, of which these only read.
     *
     * @param list<string> $readToolNames
     */
    public function offeringTools(array $readToolNames): self
    {
        return new self($this->uid, $this->uuid, $readToolNames);
    }

    /**
     * The correlation id every provider call of this run carries.
     *
     * It is the run uuid verbatim — deliberately not a second identifier, so a
     * telemetry row can be resolved to its run without a mapping table.
     */
    public function correlationId(): string
    {
        return $this->uuid;
    }
}
