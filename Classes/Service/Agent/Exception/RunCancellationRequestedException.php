<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use RuntimeException;

/**
 * Internal control-flow signal for cooperative cancellation (ADR-103).
 *
 * Thrown by the AgentRuntime's cancellation probe (inside the trace hook, at a
 * step boundary) when the run row turned CANCELLED while the loop was
 * executing. Deliberately NOT an {@see AgentRuntimeException}: it is not a
 * caller error — it is caught by the runtime's own ladder, mapped to
 * {@see \Netresearch\NrLlm\Domain\Enum\AgentRunOutcome::CANCELLED}, and never
 * escapes the runtime.
 */
final class RunCancellationRequestedException extends RuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self(sprintf('Run %s was cancelled; the loop stops at this step boundary.', $runUuid), 1784800001);
    }
}
