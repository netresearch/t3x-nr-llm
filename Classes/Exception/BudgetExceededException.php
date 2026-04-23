<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use RuntimeException;
use Throwable;

/**
 * Thrown when BudgetMiddleware denies a provider call.
 *
 * Carries the full BudgetCheckResult so consumers (controllers, logs,
 * flash messages) can surface which bucket tripped, the current usage,
 * and the limit without re-running the check.
 */
final class BudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly BudgetCheckResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct($result->reason, 0, $previous);
    }
}
