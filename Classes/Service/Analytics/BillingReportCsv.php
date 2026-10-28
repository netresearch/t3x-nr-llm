<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * The billing report as ONE CSV table a spreadsheet imports in one step.
 *
 * Every row has the same columns; the first column, `section`, says what the
 * row is (`total`, `active_editor`, `without_backend_user`,
 * `entitled_editor`, `by_kind`), so a filter or a pivot table on it splits
 * the file. Columns that do not apply to a section stay empty. A later site
 * dimension is one more column.
 *
 * Format (RFC 4180): comma separator, double-quote enclosure, CRLF line ends,
 * UTF-8 without BOM. Numbers are machine-readable: dot decimal, no thousands
 * separator, no currency sign; amounts carry 6 decimals and the currency is
 * in the column name. Text cells that a spreadsheet would read as a formula
 * (leading `=`, `+`, `-`, `@`, tab or carriage return) get a leading `'`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class BillingReportCsv
{
    public const HEADER = [
        'section',
        'month',
        'be_user_uid',
        'username',
        'real_name',
        'account_state',
        'service_type',
        'source_extension',
        'task_uid',
        'task',
        'requests',
        'tokens',
        'estimated_cost_usd',
        'markup_percent',
        'markup_usd',
        'total_usd',
        'state_as_of',
    ];

    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function fileName(BillingMonth $month): string
    {
        return 'nr-llm-billing-' . $month->key() . '.csv';
    }

    public static function render(MonthlyBillingReport $report): string
    {
        $month = $report->month->key();
        $rows  = [self::HEADER];

        $rows[] = self::row('total', $month, [
            'requests'           => (string) $report->requests,
            'tokens'             => (string) $report->tokens,
            'estimated_cost_usd' => BillingAmounts::decimal($report->amounts->costMicros),
            'markup_percent'     => (string) $report->amounts->markupPercent,
            'markup_usd'         => BillingAmounts::decimal($report->amounts->markupMicros),
            'total_usd'          => BillingAmounts::decimal($report->amounts->totalMicros),
        ]);

        foreach ($report->activeEditors as $editor) {
            $rows[] = self::row('active_editor', $month, [
                'be_user_uid'        => (string) $editor['uid'],
                'username'           => self::text($editor['username']),
                'real_name'          => self::text($editor['realName']),
                'account_state'      => self::accountState($editor),
                'requests'           => (string) $editor['requests'],
                'tokens'             => (string) $editor['tokens'],
                'estimated_cost_usd' => BillingAmounts::decimal(BillingAmounts::toMicros($editor['cost'])),
            ]);
        }

        if ($report->withoutUser !== null) {
            $rows[] = self::row('without_backend_user', $month, [
                'requests'           => (string) $report->withoutUser['requests'],
                'tokens'             => (string) $report->withoutUser['tokens'],
                'estimated_cost_usd' => BillingAmounts::decimal(BillingAmounts::toMicros($report->withoutUser['cost'])),
            ]);
        }

        $asOf = $report->entitlementAsOf->format('Y-m-d');
        foreach ($report->entitledEditors as $editor) {
            $rows[] = self::row('entitled_editor', $month, [
                'be_user_uid' => (string) $editor['uid'],
                'username'    => self::text($editor['username']),
                'real_name'   => self::text($editor['realName']),
                'state_as_of' => $asOf,
            ]);
        }

        foreach ($report->byKind as $kind) {
            $rows[] = self::row('by_kind', $month, [
                'service_type'       => self::text($kind['serviceType']),
                'source_extension'   => self::text($kind['sourceExtension']),
                'task_uid'           => $kind['taskUid'] > 0 ? (string) $kind['taskUid'] : '',
                'task'               => self::text($kind['taskName']),
                'requests'           => (string) $kind['requests'],
                'tokens'             => (string) $kind['tokens'],
                'estimated_cost_usd' => BillingAmounts::decimal(BillingAmounts::toMicros($kind['cost'])),
            ]);
        }

        return self::encode($rows);
    }

    /**
     * Neutralise a text cell a spreadsheet would evaluate as a formula.
     */
    public static function text(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private static function row(string $section, string $month, array $values): array
    {
        $row = [];
        foreach (self::HEADER as $column) {
            $row[] = match ($column) {
                'section' => $section,
                'month'   => $month,
                default   => $values[$column] ?? '',
            };
        }

        return $row;
    }

    /**
     * @param array{admin: bool, disabled: bool, deleted: bool} $editor
     */
    private static function accountState(array $editor): string
    {
        $states = array_keys(array_filter([
            'admin'    => $editor['admin'],
            'disabled' => $editor['disabled'],
            'deleted'  => $editor['deleted'],
        ]));

        return implode(' ', $states);
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function encode(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV export.', 1793232001);
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
