<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The event-stream position for the resume could not be determined.
 *
 * Thrown by approve(): resuming anyway would corrupt the run's event sequence, so the resume is refused before the claim - the run stays suspended and the approval can be retried.
 */
final class RunStateUnavailableException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The event-stream position for the resume could not be determined.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
