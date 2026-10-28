<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One backend user's own usage today and this month, against the limits of
 * their budget record.
 *
 * `$hasPersonalLimit` is true only when enforcement applies the record: it
 * exists, is active and sets at least one limit above 0. A record that is
 * switched off, or that limits nothing, is reported as no personal limit,
 * because {@see \Netresearch\NrLlm\Service\BudgetService::check()} allows every
 * call against it. The meters then carry limit 0.
 *
 * @api
 */
final readonly class UserBudgetStatus
{
    public function __construct(
        public bool $hasPersonalLimit,
        public BudgetWindowStatus $today,
        public BudgetWindowStatus $month,
    ) {}

    /**
     * @return array{
     *   hasPersonalLimit: bool,
     *   today: array<string, array{used: string, limit: string, limited: bool, percent: int}>,
     *   month: array<string, array{used: string, limit: string, limited: bool, percent: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'hasPersonalLimit' => $this->hasPersonalLimit,
            'today'            => $this->today->toArray(),
            'month'            => $this->month->toArray(),
        ];
    }
}
