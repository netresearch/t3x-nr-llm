<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;

/**
 * Shapes the rows of the usage reports for display: a gap-free daily series,
 * usernames on the per-user rows, and the list-view usage columns. Pure
 * functions over rows {@see \Netresearch\NrLlm\Service\UsageAnalyticsService}
 * or a list controller already fetched; no database access.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class UsageReportShaper
{
    /**
     * @param array<int, array<string, mixed>> $rows raw DB rows ordered by request_date ASC
     *
     * @return list<array{date: string, cost: float, requests: int, tokens: int}>
     */
    public static function fillDailyGaps(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $ts = is_numeric($row['request_date'] ?? null) ? (int) $row['request_date'] : null;
            if ($ts === null) {
                continue;
            }

            // setTimestamp() keeps the object's default (local) timezone; '@'.$ts
            // would force UTC and misbucket local-midnight request_date values.
            $key = (new DateTimeImmutable())->setTimestamp($ts)->format('Y-m-d');

            // Add up, never replace: one local day can hold several
            // request_date values (PHP's local midnight next to an SQL seed's
            // server midnight, or rows from before a timezone change), and the
            // query groups by the raw value. Replacing kept only the last
            // bucket of the day, so the chart no longer added up to the cards.
            $byDate[$key] ??= ['cost' => 0.0, 'requests' => 0, 'tokens' => 0];
            $byDate[$key]['cost'] += is_numeric($row['cost'] ?? null) ? (float) $row['cost'] : 0.0;
            $byDate[$key]['requests'] += is_numeric($row['requests'] ?? null) ? (int) $row['requests'] : 0;
            $byDate[$key]['tokens'] += is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0;
        }

        $series = [];
        $cursor = $from->setTime(0, 0, 0);
        $end    = $to->setTime(0, 0, 0);
        while ($cursor <= $end) {
            $key      = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                // Back to the column's precision (decimal(14,6)): the sum of
                // two buckets is a float addition, and 0.5 + 0.07 would reach
                // the chart and its screen-reader table as 0.5700000000000001.
                'cost'     => round($byDate[$key]['cost'] ?? 0.0, 6),
                'requests' => $byDate[$key]['requests'] ?? 0,
                'tokens'   => $byDate[$key]['tokens'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * @param array<int, array<string, mixed>> $usageRows rows with be_user/cost/requests/tokens
     * @param array<int, string>               $userMap   beUserUid => username
     *
     * @return list<array{beUserUid: int, label: string, cost: float, requests: int, tokens: int}>
     */
    public static function mergeUsernames(array $usageRows, array $userMap): array
    {
        $out = [];
        foreach ($usageRows as $row) {
            $uid = is_numeric($row['be_user'] ?? null) ? (int) $row['be_user'] : 0;
            if ($uid === 0) {
                $label = 'system';
            } elseif (isset($userMap[$uid]) && $userMap[$uid] !== '') {
                $label = $userMap[$uid];
            } else {
                $label = 'user #' . $uid;
            }

            $out[] = [
                'beUserUid' => $uid,
                'label'     => $label,
                'cost'      => is_numeric($row['cost'] ?? null) ? (float) $row['cost'] : 0.0,
                'requests'  => is_numeric($row['requests'] ?? null) ? (int) $row['requests'] : 0,
                'tokens'    => is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Format raw per-key totals into display strings for the list-view columns,
     * in the reader's locale (NEXT-170): `~` and the USD amount, two decimals.
     *
     * @param array<int|string, array{cost: float, requests: int, tokens: int}> $totals
     *
     * @return array{cost: array<int|string, string>, requests: array<int|string, string>, tokens: array<int|string, string>}
     */
    public static function formatUsageColumns(array $totals, LocalizedNumberFormatter $formatter): array
    {
        $cost     = [];
        $requests = [];
        $tokens   = [];
        foreach ($totals as $key => $t) {
            $cost[$key]     = '~' . $formatter->usd($t['cost'], 2);
            $requests[$key] = $formatter->number($t['requests']);
            $tokens[$key]   = $formatter->number($t['tokens']);
        }

        return ['cost' => $cost, 'requests' => $requests, 'tokens' => $tokens];
    }
}
