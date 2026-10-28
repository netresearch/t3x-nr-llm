<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;

/**
 * One axis of one budget window as an editor reads it: what was used and the
 * limit it is measured against.
 *
 * A limit of 0 means "no limit on this axis", the same reading
 * {@see \Netresearch\NrLlm\Service\BudgetService::check()} gives it.
 *
 * The formatted values come from the reader's {@see LocalizedNumberFormatter},
 * the one the Analytics module prints with, so the page and the JSON refresh
 * show the same digits. Cost carries its dollar sign, placed where the
 * reader's locale puts it ("$11.40", "11,40 $"); counts carry none. Fluid
 * reads `{meter.limited}`, `{meter.percent}`, `{meter.usedFormatted}` and
 * `{meter.limitFormatted}`.
 *
 * @api
 */
final readonly class BudgetMeter
{
    public function __construct(
        public float $used,
        public float $limit,
        /** Cost is shown with two decimals, counts without. */
        public bool $isCost,
        public LocalizedNumberFormatter $formatter,
    ) {}

    public function isLimited(): bool
    {
        return $this->limit > 0.0;
    }

    /**
     * Share of the limit used, 0-100, rounded down: the bar is full only once
     * the limit is used up, as BudgetService::check() sees it. Usage above the
     * limit reads 100, and the text next to it carries the real number.
     */
    public function getPercent(): int
    {
        if (!$this->isLimited()) {
            return 0;
        }

        return (int) floor(min(100.0, max(0.0, $this->used / $this->limit * 100.0)));
    }

    public function getUsedFormatted(): string
    {
        return $this->format($this->used);
    }

    public function getLimitFormatted(): string
    {
        return $this->format($this->limit);
    }

    /**
     * @return array{used: string, limit: string, limited: bool, percent: int}
     */
    public function toArray(): array
    {
        return [
            'used'    => $this->getUsedFormatted(),
            'limit'   => $this->getLimitFormatted(),
            'limited' => $this->isLimited(),
            'percent' => $this->getPercent(),
        ];
    }

    private function format(float $value): string
    {
        return $this->isCost ? $this->formatter->usd($value, 2) : $this->formatter->number($value);
    }
}
