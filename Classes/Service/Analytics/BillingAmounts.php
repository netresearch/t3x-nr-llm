<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

/**
 * Estimated cost, markup and the total of one billing month, in USD.
 *
 * Calculated in whole micro-dollars (the 6 decimals `estimated_cost` is
 * stored with), so the markup is applied once, to the unrounded month total,
 * and rounded half up to one micro-dollar; cost + markup = total exactly.
 * The CSV keeps all 6 decimals. The page shows cents: cost and total are
 * each rounded half up to a cent, and the displayed markup is the rounded
 * total minus the rounded cost, so the three lines on the page add up
 * (0.125 at 100 % shows 0.13 + 0.12 = 0.25, not 0.13 + 0.13).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BillingAmounts
{
    private const MICROS = 1_000_000;

    private const MICROS_PER_CENT = 10_000;

    private function __construct(
        public int $costMicros,
        public int $markupPercent,
        public int $markupMicros,
        public int $totalMicros,
        public float $cost,
        public float $markup,
        public float $total,
        public float $displayCost,
        public float $displayMarkup,
        public float $displayTotal,
    ) {}

    public static function of(float $estimatedCost, BillingMarkup $markup): self
    {
        $costMicros   = self::toMicros($estimatedCost);
        $markupMicros = $markup->markupMicros($costMicros);
        $totalMicros  = $costMicros + $markupMicros;
        $costCents    = self::toCents($costMicros);
        $totalCents   = self::toCents($totalMicros);

        return new self(
            $costMicros,
            $markup->percent,
            $markupMicros,
            $totalMicros,
            $costMicros / self::MICROS,
            $markupMicros / self::MICROS,
            $totalMicros / self::MICROS,
            $costCents / 100,
            ($totalCents - $costCents) / 100,
            $totalCents / 100,
        );
    }

    /**
     * Whole cents, rounded half up.
     */
    private static function toCents(int $micros): int
    {
        return intdiv($micros + intdiv(self::MICROS_PER_CENT, 2), self::MICROS_PER_CENT);
    }

    public static function toMicros(float $amount): int
    {
        return (int) round(max(0.0, $amount) * self::MICROS);
    }

    /**
     * A machine-readable decimal: dot, 6 decimals, no thousands separator.
     */
    public static function decimal(int $micros): string
    {
        return sprintf('%d.%06d', intdiv($micros, self::MICROS), $micros % self::MICROS);
    }
}
