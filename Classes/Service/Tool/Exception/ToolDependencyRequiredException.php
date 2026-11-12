<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Exception;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;

/**
 * The tool loop suspended on an external dependency (ADR-281): the dependency
 * sibling of {@see ToolInputRequiredException}. The caller MUST catch it
 * before any generic Throwable and persist the state with its checkpoint, or
 * settle the run failed when it cannot.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ToolDependencyRequiredException extends RuntimeException
{
    public function __construct(
        public readonly SuspendedRunState $state,
        public readonly DependencyCheckpoint $checkpoint,
    ) {
        parent::__construct('Tool loop suspended: a called tool waits for an external operation.', 1799200102);
    }
}
