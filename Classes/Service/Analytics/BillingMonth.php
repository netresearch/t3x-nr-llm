<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;

/**
 * One calendar month of the billing report, in the server's timezone.
 *
 * tx_nrllm_service_usage stores `request_date` as the local midnight of the
 * day a request ran, and every query of {@see \Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface}
 * compares with `>= from` and `<= to`. `lastSecond` is therefore the last
 * second of the month (23:59:59 on its last day), never the first second of
 * the next one: that would pull the next month's first-day bucket in.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BillingMonth
{
    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $lastSecond,
    ) {}

    public static function of(int $year, int $month): self
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return new self($start, $start->modify('first day of next month')->modify('-1 second'));
    }

    /**
     * The default: the last complete month before $now.
     */
    public static function previous(DateTimeImmutable $now): self
    {
        $previous = $now->modify('first day of last month');

        return self::of((int) $previous->format('Y'), (int) $previous->format('n'));
    }

    public static function current(DateTimeImmutable $now): self
    {
        return self::of((int) $now->format('Y'), (int) $now->format('n'));
    }

    /**
     * Read a `YYYY-MM` request value. Anything else — and a month that has
     * not started yet — falls back to the previous complete month.
     */
    public static function fromParameter(mixed $value, DateTimeImmutable $now): self
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1) {
            return self::previous($now);
        }

        $month = self::of((int) $m[1], (int) $m[2]);
        if ($month->start > $now) {
            return self::previous($now);
        }

        return $month;
    }

    /**
     * The months the selector offers, newest first: the running month, then
     * the $count months before it.
     *
     * @return list<self>
     */
    public static function choices(DateTimeImmutable $now, int $count = 12): array
    {
        $choices = [];
        $cursor  = self::current($now)->start;
        for ($i = 0; $i <= $count; ++$i) {
            $choices[] = self::of((int) $cursor->format('Y'), (int) $cursor->format('n'));
            $cursor    = $cursor->modify('first day of last month');
        }

        return $choices;
    }

    /**
     * `YYYY-MM`, the request value and the part of the export file name.
     */
    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function isRunning(DateTimeImmutable $now): bool
    {
        return $now >= $this->start && $now <= $this->lastSecond;
    }
}
