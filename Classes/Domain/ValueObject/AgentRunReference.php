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
 * @api
 */
final readonly class AgentRunReference
{
    public function __construct(
        public int $uid,
        public string $uuid,
    ) {}

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
