<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\BillingMonth;
use Netresearch\NrLlm\Service\Analytics\EditorEntitlementDirectory;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReport;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReportService;
use Netresearch\NrLlm\Service\UsageAnalyticsService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The billing report against real rows in Europe/Berlin: October 2026 ends
 * on a daylight-saving change, so a fixed-length month would be wrong.
 */
#[CoversClass(MonthlyBillingReportService::class)]
#[CoversClass(EditorEntitlementDirectory::class)]
#[CoversClass(UsageAnalyticsService::class)]
final class MonthlyBillingReportServiceTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_service_usage';

    private string $timezone;

    private mixed $nrLlmConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        $confVars                 = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensions               = is_array($confVars) ? ($confVars['EXTENSIONS'] ?? null) : null;
        $this->nrLlmConfiguration = is_array($extensions) ? ($extensions['nr_llm'] ?? null) : null;
        $this->importFixture('BillingReport.csv');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm'] = $this->nrLlmConfiguration;
        parent::tearDown();
    }

    #[Test]
    public function theMonthTakesItsFirstAndLastDayAndNothingAround(): void
    {
        $this->insertUsage('2026-10-01 00:00:00', 31, 1.0);
        $this->insertUsage('2026-10-31 00:00:00', 31, 2.0);
        // Outside: the next month's first bucket, the previous month's last
        // bucket, and the very last second before the month starts.
        $this->insertUsage('2026-11-01 00:00:00', 31, 100.0);
        $this->insertUsage('2026-09-30 00:00:00', 31, 1000.0);
        $this->insertUsage('2026-09-30 23:59:59', 31, 10000.0);

        $report = $this->build();

        self::assertSame('3.000000', $this->decimal($report->amounts->costMicros));
        self::assertSame(2, $report->requests);
    }

    #[Test]
    public function theMarkupIsAddedOnceToTheMonthTotal(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['billing']['markupPercent'] = '10';
        $this->insertUsage('2026-10-05 00:00:00', 31, 60.0);
        $this->insertUsage('2026-10-06 00:00:00', 32, 40.0);

        $report = $this->build();

        self::assertSame(10, $report->amounts->markupPercent);
        self::assertSame('100.000000', $this->decimal($report->amounts->costMicros));
        self::assertSame('10.000000', $this->decimal($report->amounts->markupMicros));
        self::assertSame('110.000000', $this->decimal($report->amounts->totalMicros));
        self::assertFalse($report->markupAdjusted);
        // Stored costs are untouched.
        self::assertSame(100.0, array_sum(array_column($report->activeEditors, 'cost')));
    }

    #[Test]
    public function everyUserWithARequestIsActiveWhateverTheirAccountStateIsToday(): void
    {
        $this->insertUsage('2026-10-10 00:00:00', 31, 1.0);
        $this->insertUsage('2026-10-11 00:00:00', 33, 2.0);  // disabled today
        $this->insertUsage('2026-10-12 00:00:00', 34, 0.5);  // deleted today
        $this->insertUsage('2026-10-13 00:00:00', 1, 0.25);  // administrator
        $this->insertUsage('2026-10-14 00:00:00', 99, 0.125); // no be_users row left
        $this->insertUsage('2026-10-15 00:00:00', 0, 4.0);   // scheduler: no editor
        $this->insertUsage('2026-11-01 00:00:00', 32, 9.0);  // next month: not active

        $report = $this->build();

        $byUid = array_column($report->activeEditors, null, 'uid');
        // Ordered by cost, as the per-user table of the Analytics page.
        self::assertSame([33, 31, 34, 1, 99], array_column($report->activeEditors, 'uid'));
        self::assertSame(5, $report->getActiveEditorCount());
        self::assertTrue($byUid[33]['disabled']);
        self::assertTrue($byUid[34]['deleted']);
        self::assertSame('deleted', $byUid[34]['username'], 'A deleted user is listed under the name they had.');
        self::assertTrue($byUid[1]['admin']);
        self::assertTrue($byUid[99]['deleted']);
        self::assertSame(['cost' => 4.0, 'requests' => 1, 'tokens' => 100], $report->withoutUser);
        // The rows add up to the month total.
        self::assertEqualsWithDelta(
            $report->amounts->cost,
            array_sum(array_column($report->activeEditors, 'cost')) + 4.0,
            0.000001,
        );
    }

    #[Test]
    public function entitledAreEditorsWhoCanLogInHoldTheGrantAndOpenTheModule(): void
    {
        $report = $this->build();

        self::assertSame(
            ['direct', 'module-subgroup', 'nested', 'user-module'],
            array_column($report->entitledEditors, 'username'),
            'Not: the administrator, a disabled, deleted or expired user, a grant without the module, '
            . 'a module without the grant, a grant from a hidden group.',
        );
        self::assertSame(4, $report->getEntitledEditorCount());
        // Only the administrator who can log in now: not the disabled one, and
        // not core's command-line user `_cli_`, which core creates as an admin.
        self::assertSame(1, $report->administratorCount);
    }

    #[Test]
    public function resultsAreGroupedByServiceTypeExtensionAndTask(): void
    {
        $this->insertUsage('2026-10-10 00:00:00', 31, 1.0, ['service_type' => 'chat', 'source_extension' => 'my_ext', 'task_uid' => 7]);
        $this->insertUsage('2026-10-11 00:00:00', 32, 2.0, ['service_type' => 'chat', 'source_extension' => 'my_ext', 'task_uid' => 7]);
        $this->insertUsage('2026-10-12 00:00:00', 31, 0.5, ['service_type' => 'translation', 'source_extension' => '', 'task_uid' => 0]);
        $this->insertUsage('2026-10-13 00:00:00', 31, 0.25, ['service_type' => 'chat', 'source_extension' => 'my_ext', 'task_uid' => 0]);

        $report = $this->build();

        self::assertSame([
            ['serviceType' => 'chat', 'sourceExtension' => 'my_ext', 'taskUid' => 7, 'taskName' => 'Summarise a page', 'cost' => 3.0, 'requests' => 2, 'tokens' => 200],
            ['serviceType' => 'translation', 'sourceExtension' => '', 'taskUid' => 0, 'taskName' => '', 'cost' => 0.5, 'requests' => 1, 'tokens' => 100],
            ['serviceType' => 'chat', 'sourceExtension' => 'my_ext', 'taskUid' => 0, 'taskName' => '', 'cost' => 0.25, 'requests' => 1, 'tokens' => 100],
        ], $report->byKind);
    }

    private function build(): MonthlyBillingReport
    {
        $service = $this->get(MonthlyBillingReportService::class);
        self::assertInstanceOf(MonthlyBillingReportService::class, $service);

        return $service->build(BillingMonth::of(2026, 10), new DateTimeImmutable('2026-11-03 09:00:00'));
    }

    private function decimal(int $micros): string
    {
        return sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000);
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function insertUsage(string $when, int $beUser, float $cost, array $overrides = []): void
    {
        $ts = (new DateTimeImmutable($when))->getTimestamp();
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->insert(self::TABLE, array_merge([
            'pid'                => 0,
            'service_type'       => 'chat',
            'service_provider'   => 'openai',
            'configuration_uid'  => 0,
            'model_uid'          => 1,
            'model_id'           => 'gpt-5',
            'task_uid'           => 0,
            'source_extension'   => '',
            'be_user'            => $beUser,
            'request_count'      => 1,
            'tokens_used'        => 100,
            'prompt_tokens'      => 60,
            'completion_tokens'  => 40,
            'characters_used'    => 0,
            'audio_seconds_used' => 0,
            'images_generated'   => 0,
            'estimated_cost'     => $cost,
            'request_date'       => $ts,
            'tstamp'             => $ts,
            'crdate'             => $ts,
        ], $overrides));
    }
}
