<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Exception;

use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;

/**
 * Thrown by a {@see \Netresearch\NrLlm\Service\Tool\DependencyToolInterface}
 * from execute() when the external operation was accepted and has not
 * finished (ADR-281). Control flow, not failure: the loop catches it and
 * suspends the run. The checkpoint names no tool call yet; the loop fills it
 * in.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ToolDependencyPendingException extends RuntimeException
{
    /**
     * @param string $notice what the run timeline records for the call while it waits
     */
    public function __construct(
        public readonly DependencyCheckpoint $checkpoint,
        public readonly string $notice,
    ) {
        parent::__construct('A called tool handed its work to an external operation that has not finished.', 1799200101);
    }
}
