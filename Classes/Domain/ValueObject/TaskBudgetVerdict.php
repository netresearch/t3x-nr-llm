<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;

/**
 * Whether the next run of a task passes the budget, as the run form states it
 * (NEXT-170).
 *
 * Three states, because the budget is asked twice:
 *
 * - `denied`: `check()` with planned cost 0 refuses — exactly what the
 *   execution enforces, so the run will be refused.
 * - `exceedsAtUpperEstimate`: the run starts, but `check()` with the upper cost
 *   estimate refuses. The execution plans with no cost, so the run is not
 *   refused; it may take the user past the named limit.
 * - `allowed`: both checks pass, or only the first was possible because the run
 *   cannot be priced.
 *
 * `$exceededLimit` is a {@see BudgetCheckResult} `LIMIT_*` key. The numbers
 * behind it are deliberately not carried: for a configuration limit they are
 * the usage of every user of that configuration.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TaskBudgetVerdict
{
    public const ALLOWED = 'allowed';

    public const EXCEEDS_AT_UPPER_ESTIMATE = 'exceedsAtUpperEstimate';

    public const DENIED = 'denied';

    private function __construct(
        public string $state,
        public string $exceededLimit,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED, BudgetCheckResult::LIMIT_NONE);
    }

    public static function exceedsAtUpperEstimate(string $exceededLimit): self
    {
        return new self(self::EXCEEDS_AT_UPPER_ESTIMATE, $exceededLimit);
    }

    public static function denied(string $exceededLimit): self
    {
        return new self(self::DENIED, $exceededLimit);
    }
}
