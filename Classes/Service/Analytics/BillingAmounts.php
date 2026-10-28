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
 * The page rounds to cents for display, the CSV keeps all 6 decimals.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BillingAmounts
{
    private const MICROS = 1_000_000;

    private function __construct(
        public int $costMicros,
        public int $markupPercent,
        public int $markupMicros,
        public int $totalMicros,
        public float $cost,
        public float $markup,
        public float $total,
    ) {}

    public static function of(float $estimatedCost, BillingMarkup $markup): self
    {
        $costMicros   = self::toMicros($estimatedCost);
        $markupMicros = $markup->markupMicros($costMicros);
        $totalMicros  = $costMicros + $markupMicros;

        return new self(
            $costMicros,
            $markup->percent,
            $markupMicros,
            $totalMicros,
            $costMicros / self::MICROS,
            $markupMicros / self::MICROS,
            $totalMicros / self::MICROS,
        );
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
