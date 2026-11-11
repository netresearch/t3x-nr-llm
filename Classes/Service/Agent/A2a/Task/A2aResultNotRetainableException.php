<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * The run of an A2A task completed under an answer level that keeps no
 * content, or its result could not be written (ADR-282): the run and the task
 * end FAILED, never COMPLETED without the result. Only its class is stored on
 * the run.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aResultNotRetainableException extends RuntimeException
{
    public const REASON = 'RESULT_NOT_RETAINABLE';

    public const WRITE_FAILED = 'RESULT_NOT_STORED';
}
