<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;

/**
 * Resolves a dashboard date-range preset into a concrete [from, to] window.
 *
 * All windows are anchored to midnight because tx_nrllm_service_usage stores
 * request_date as a midnight timestamp (one row bucket per day). `to` is the
 * current day's midnight (inclusive lower-or-equal comparison covers today).
 */
final readonly class AnalyticsPeriod
{
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $preset,
    ) {}

    public static function fromPreset(string $preset, DateTimeImmutable $now): self
    {
        $today = $now->setTime(0, 0, 0);

        return match ($preset) {
            '7d'    => new self($today->modify('-6 days'), $today, '7d'),
            '90d'   => new self($today->modify('-89 days'), $today, '90d'),
            'month' => new self($today->modify('first day of this month'), $today, 'month'),
            default => new self($today->modify('-29 days'), $today, '30d'),
        };
    }

    /**
     * Valid presets, in display order, for building the range switcher.
     *
     * @return list<string>
     */
    public static function presets(): array
    {
        return ['7d', '30d', '90d', 'month'];
    }
}
