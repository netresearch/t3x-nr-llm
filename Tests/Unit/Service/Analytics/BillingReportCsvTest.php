<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\BillingAmounts;
use Netresearch\NrLlm\Service\Analytics\BillingMarkup;
use Netresearch\NrLlm\Service\Analytics\BillingMonth;
use Netresearch\NrLlm\Service\Analytics\BillingReportCsv;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The export is read back with a CSV parser, not matched as a string: what
 * counts is what a spreadsheet gets out of it.
 */
#[CoversClass(BillingReportCsv::class)]
final class BillingReportCsvTest extends TestCase
{
    #[Test]
    public function aNameWithCommaQuoteAndNewlineSurvivesTheRoundTrip(): void
    {
        $names = ['Doe, Jane', 'say "hi"', "two\nlines", "carriage\r\nreturn"];
        $rows  = $this->parse(BillingReportCsv::render($this->report(activeNames: $names)));

        self::assertSame($names, array_column($this->section($rows, 'active_editor'), 'username'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulaStarts(): iterable
    {
        yield 'equals' => ['=HYPERLINK("http://example.org","x")'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab' => ["\t=1"];
        yield 'carriage return' => ["\r=1"];
    }

    #[Test]
    #[DataProvider('formulaStarts')]
    public function aTextCellAFormulaWouldStartWithIsNeutralised(string $value): void
    {
        $rows = $this->parse(BillingReportCsv::render($this->report(activeNames: [$value], extension: $value, taskName: $value, entitledNames: [$value])));

        $expected = "'" . $value;
        self::assertSame($expected, $this->section($rows, 'active_editor')[0]['username']);
        self::assertSame($expected, $this->section($rows, 'active_editor')[0]['real_name']);
        self::assertSame($expected, $this->section($rows, 'entitled_editor')[0]['username']);
        self::assertSame($expected, $this->section($rows, 'by_kind')[0]['source_extension']);
        self::assertSame($expected, $this->section($rows, 'by_kind')[0]['task']);
    }

    #[Test]
    public function anOrdinaryNameIsLeftAlone(): void
    {
        $rows = $this->parse(BillingReportCsv::render($this->report(activeNames: ['jane-doe', "o'brien"])));

        self::assertSame(['jane-doe', "o'brien"], array_column($this->section($rows, 'active_editor'), 'username'));
    }

    #[Test]
    public function numbersAreMachineReadableAndTheCurrencyIsInTheHeader(): void
    {
        $csv  = BillingReportCsv::render($this->report(cost: 1234.5, markupPercent: '10'));
        $rows = $this->parse($csv);

        self::assertSame(BillingReportCsv::HEADER, array_keys($rows[0]));
        self::assertContains('estimated_cost_usd', BillingReportCsv::HEADER);

        $total = $this->section($rows, 'total')[0];
        self::assertSame('2026-09', $total['month']);
        self::assertSame('1234567', $total['requests']);
        self::assertSame('1234.500000', $total['estimated_cost_usd']);
        self::assertSame('10', $total['markup_percent']);
        self::assertSame('123.450000', $total['markup_usd']);
        self::assertSame('1357.950000', $total['total_usd']);
        self::assertStringNotContainsString('$', $csv);
    }

    #[Test]
    public function everySectionIsOneTableWithTheSameColumns(): void
    {
        $csv  = BillingReportCsv::render($this->report(activeNames: ['jane'], entitledNames: ['joe']));
        $rows = $this->parse($csv);

        self::assertSame(
            ['total', 'active_editor', 'without_backend_user', 'entitled_editor', 'by_kind'],
            array_values(array_unique(array_column($rows, 'section'))),
        );
        self::assertSame('2026-10-28', $this->section($rows, 'entitled_editor')[0]['state_as_of']);
        self::assertSame('admin disabled', $this->section($rows, 'active_editor')[0]['account_state']);
        self::assertSame('42', $this->section($rows, 'by_kind')[0]['task_uid']);
    }

    #[Test]
    public function theFileIsUtf8WithoutBomAndUsesCrlf(): void
    {
        $csv = BillingReportCsv::render($this->report(activeNames: ['Jürgen']));

        self::assertStringStartsWith('section,month,', $csv);
        self::assertStringEndsWith("\r\n", $csv);
        self::assertStringContainsString('Jürgen', $csv);
        self::assertSame('nr-llm-billing-2026-09.csv', BillingReportCsv::fileName(BillingMonth::of(2026, 9)));
    }

    /**
     * @param list<string> $activeNames
     * @param list<string> $entitledNames
     */
    private function report(
        array $activeNames = ['jane'],
        string $extension = 'my_ext',
        string $taskName = 'Summarise',
        array $entitledNames = ['joe'],
        float $cost = 1.0,
        string $markupPercent = '0',
    ): MonthlyBillingReport {
        $active = [];
        foreach ($activeNames as $i => $name) {
            $active[] = [
                'uid'      => $i + 10,
                'username' => $name,
                'realName' => $name,
                'admin'    => true,
                'disabled' => true,
                'deleted'  => false,
                'cost'     => 0.5,
                'requests' => 3,
                'tokens'   => 300,
            ];
        }

        $entitled = [];
        foreach ($entitledNames as $i => $name) {
            $entitled[] = ['uid' => $i + 20, 'username' => $name, 'realName' => ''];
        }

        return new MonthlyBillingReport(
            month: BillingMonth::of(2026, 9),
            monthIsRunning: false,
            requests: 1234567,
            tokens: 7654321,
            amounts: BillingAmounts::of($cost, BillingMarkup::fromConfiguredValue($markupPercent)),
            markupAdjusted: false,
            activeEditors: $active,
            withoutUser: ['cost' => 0.25, 'requests' => 1, 'tokens' => 10],
            entitledEditors: $entitled,
            administratorCount: 1,
            entitlementAsOf: new DateTimeImmutable('2026-10-28 14:00:00'),
            byKind: [[
                'serviceType'     => 'chat',
                'sourceExtension' => $extension,
                'taskUid'         => 42,
                'taskName'        => $taskName,
                'cost'            => 0.75,
                'requests'        => 4,
                'tokens'          => 400,
            ]],
        );
    }

    /**
     * The data rows, keyed by the header row.
     *
     * @return list<array<string, string>>
     */
    private function parse(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, $csv);
        rewind($handle);

        $header = null;
        $rows   = [];
        while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $fields = array_map(static fn (?string $field): string => (string) $field, $fields);
            if ($header === null) {
                $header = $fields;
                continue;
            }

            $rows[] = array_combine($header, $fields);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, string>>
     */
    private function section(array $rows, string $section): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $row['section'] === $section));
    }
}
