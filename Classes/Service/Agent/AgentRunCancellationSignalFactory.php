<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;

/**
 * Builds one {@see AgentRunCancellationSignal} per outbound call (#774).
 *
 * It exists so the two collaborators the signal needs -- the persister that
 * holds the run's status and the clock that bounds how often it is read -- are
 * wired once by the container instead of being threaded through every object
 * between the tool provider and the transport. {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpTool}
 * is built by hand per catalogue row, so each argument it gains is one every
 * construction site has to carry.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class AgentRunCancellationSignalFactory
{
    public function __construct(
        private AgentRunPersister $persister,
        private McpClockInterface $clock,
    ) {}

    public function forRun(string $runUuid): AgentRunCancellationSignal
    {
        return new AgentRunCancellationSignal($this->persister, $this->clock, $runUuid);
    }
}
