<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The persisted suspended-run state could not be decoded.
 *
 * Thrown by approve(): the stored JSON is unreadable, so the pending tool calls cannot be reconstructed.
 */
final class CorruptSuspendedStateException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The persisted suspended-run state could not be decoded.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
