<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * `TaskState` of the pinned proto, by its proto name (A2A-P3).
 *
 * A state the proto does not name has no case: {@see A2aTaskStatus} keeps the
 * raw value and reports it as neither terminal nor interrupted, so a v1.1
 * state can never be read as COMPLETED (A2A-P4).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aTaskState: string
{
    case UNSPECIFIED    = 'TASK_STATE_UNSPECIFIED';
    case SUBMITTED      = 'TASK_STATE_SUBMITTED';
    case WORKING        = 'TASK_STATE_WORKING';
    case COMPLETED      = 'TASK_STATE_COMPLETED';
    case FAILED         = 'TASK_STATE_FAILED';
    case CANCELED       = 'TASK_STATE_CANCELED';
    case INPUT_REQUIRED = 'TASK_STATE_INPUT_REQUIRED';
    case REJECTED       = 'TASK_STATE_REJECTED';
    case AUTH_REQUIRED  = 'TASK_STATE_AUTH_REQUIRED';

    /**
     * COMPLETED, FAILED, CANCELED and REJECTED: the proto's terminal states.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELED, self::REJECTED => true,
            default                                                       => false,
        };
    }

    /**
     * INPUT_REQUIRED and AUTH_REQUIRED: the proto's interrupted states.
     */
    public function isInterrupted(): bool
    {
        return $this === self::INPUT_REQUIRED || $this === self::AUTH_REQUIRED;
    }
}
