<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The run is unknown, not suspended for input, or carries no resumable state.
 *
 * Thrown by submitInput(): no run with this uuid is awaiting typed input (ADR-105).
 */
final class RunNotAwaitingInputException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The run is unknown, not suspended for input, or carries no resumable state.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
