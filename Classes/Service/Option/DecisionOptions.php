<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Per-call options of a native decision (ADR-211): the budget subject and
 * planned cost, the caller source, an idempotency key and a correlation id —
 * the cross-cutting fields every operation carries. The model comes from the
 * configuration; nothing here overrides what is asked.
 *
 * @api
 */
final class DecisionOptions extends AbstractOptions implements BudgetAwareOptionsInterface
{
    use BudgetFieldsTrait;

    public function __construct(?int $beUserUid = null, ?float $plannedCost = null)
    {
        $this->setBudgetFields($beUserUid, $plannedCost);
        $this->validate();
    }

    /**
     * Nothing here reaches the provider; the configuration supplies the
     * model and its call options.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }

    private function validate(): void
    {
        $this->validateBudgetFields();
    }
}
