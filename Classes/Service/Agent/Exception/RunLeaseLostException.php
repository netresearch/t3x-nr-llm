<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use RuntimeException;

/**
 * Internal control-flow signal that a queue worker has lost its lease (ADR-104).
 *
 * Thrown by the AgentRuntime's heartbeat (inside the trace hook, at a step
 * boundary) when the lease renewal affects no row — the stale-run reaper
 * reclaimed the run, another worker already re-claimed it, or a cancel/settle
 * terminated it. Like {@see RunCancellationRequestedException} it is control
 * flow, not a caller error: it is caught by the runtime's own ladder, mapped to
 * {@see \Netresearch\NrLlm\Domain\Enum\AgentRunOutcome::LEASE_LOST}, and never
 * escapes the runtime. The ladder does NOT settle the run — the row belongs to
 * its new owner, and settling it would destroy that owner's in-flight state.
 */
final class RunLeaseLostException extends RuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self(sprintf('Run %s lease was lost; this worker stops at the step boundary.', $runUuid), 1784900001);
    }
}
