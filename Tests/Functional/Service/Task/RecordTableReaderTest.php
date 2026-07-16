<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Task;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Task\RecordTableReader;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * The record-picker read path (schema introspection + fetches) against the
 * real test database: allow-listing, label detection via TCA and column
 * fallback, the sample/by-uid/all fetches, and the fail-closed exclusion
 * guard on user-supplied table names.
 */
#[CoversClass(RecordTableReader::class)]
final class RecordTableReaderTest extends AbstractFunctionalTestCase
{
    private RecordTableReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new RecordTableReader(
            $this->getService(ConnectionPool::class),
            $this->getService(TcaSchemaFactory::class),
        );
    }

    #[Test]
    public function listAllowedTablesContainsRealTablesButNotExcludedOnes(): void
    {
        $tables = $this->reader->listAllowedTables();
        $names = array_column($tables, 'name');

        self::assertContains('be_users', $names);
        self::assertContains('tx_nrllm_task', $names);
        // Exact-name exclusions.
        self::assertNotContains('sys_refindex', $names);
        self::assertNotContains('sys_registry', $names);
        // Prefix exclusions.
        foreach ($names as $name) {
            self::assertStringStartsNotWith('cache_', $name);
        }

        // Sorted by label, and every entry carries a non-empty label.
        $labels = array_column($tables, 'label');
        $sorted = $labels;
        usort($sorted, strcasecmp(...));
        self::assertSame($sorted, $labels);
    }

    #[Test]
    public function formatTableLabelHumanizesKnownPrefixes(): void
    {
        self::assertSame('Nrllm Task', $this->reader->formatTableLabel('tx_nrllm_task'));
        self::assertSame('System: Log', $this->reader->formatTableLabel('sys_log'));
        self::assertSame('Backend: Users', $this->reader->formatTableLabel('be_users'));
        self::assertSame('Frontend: Users', $this->reader->formatTableLabel('fe_users'));
        self::assertSame('Pages', $this->reader->formatTableLabel('pages'));
    }

    #[Test]
    public function detectLabelFieldUsesTcaLabelCapability(): void
    {
        // be_users TCA declares username as its label field.
        self::assertSame('username', $this->reader->detectLabelField('be_users'));
    }

    #[Test]
    public function detectLabelFieldFallsBackToCommonColumnNames(): void
    {
        // tx_nrllm_service_usage has no TCA; its columns include none of the
        // fallback names, so detection yields '' — while sys_registry-like
        // tables with a 'name'-ish column resolve via the fallback list.
        self::assertSame('', $this->reader->detectLabelField('tx_nrllm_service_usage'));
    }

    #[Test]
    public function tableHasUidColumnDistinguishesTables(): void
    {
        self::assertTrue($this->reader->tableHasUidColumn('be_users'));
        // be_sessions is keyed by ses_id and has no uid column.
        self::assertFalse($this->reader->tableHasUidColumn('be_sessions'));
    }

    #[Test]
    public function fetchSampleRecordsReturnsUidAndLabel(): void
    {
        $this->importFixture('BeUsers.csv');

        $records = $this->reader->fetchSampleRecords('be_users', '', 10);

        self::assertNotSame([], $records);
        $byUid = array_column($records, 'label', 'uid');
        self::assertSame('admin', $byUid[1]);
        self::assertSame('editor', $byUid[2]);
    }

    #[Test]
    public function fetchSampleRecordsHonorsLimitAndOrdersByUidDescForUidLabel(): void
    {
        $this->importFixture('BeUsers.csv');

        // Passing 'uid' selects only uid, orders uid DESC and uses the uid
        // value itself as the label.
        $records = $this->reader->fetchSampleRecords('be_users', 'uid', 1);

        self::assertSame([['uid' => 2, 'label' => '2']], $records);
    }

    #[Test]
    public function fetchSampleRecordsFallsBackToUidPlaceholderWithoutLabelField(): void
    {
        // tx_nrllm_service_usage has no TCA and none of the fallback label
        // columns, so no label field resolves and the placeholder is used.
        $connection = $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_service_usage');
        $connection->insert('tx_nrllm_service_usage', [
            'pid'          => 0,
            'service_type' => 'completion',
            'request_date' => 1700000000,
        ]);
        $uid = (int)$connection->lastInsertId();

        $records = $this->reader->fetchSampleRecords('tx_nrllm_service_usage', '', 5);

        self::assertSame([['uid' => $uid, 'label' => '[UID ' . $uid . ']']], $records);
    }

    #[Test]
    public function fetchSampleRecordsRejectsExcludedTable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745430001);

        $this->reader->fetchSampleRecords('sys_refindex', '', 5);
    }

    #[Test]
    public function loadRecordsByUidsReturnsFullRowsInAnyOrder(): void
    {
        $this->importFixture('BeUsers.csv');

        $records = $this->reader->loadRecordsByUids('be_users', [2, 1]);

        self::assertCount(2, $records);
        $usernames = array_column($records, 'username');
        sort($usernames);
        self::assertSame(['admin', 'editor'], $usernames);
    }

    #[Test]
    public function loadRecordsByUidsWithEmptyListShortCircuits(): void
    {
        self::assertSame([], $this->reader->loadRecordsByUids('be_users', []));
    }

    #[Test]
    public function loadRecordsByUidsRejectsExcludedTable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader->loadRecordsByUids('sys_registry', [1]);
    }

    #[Test]
    public function fetchAllReturnsRowsUpToLimit(): void
    {
        $this->importFixture('BeUsers.csv');

        self::assertCount(1, $this->reader->fetchAll('be_users', 1));
        self::assertCount(2, $this->reader->fetchAll('be_users', 10));
    }

    #[Test]
    public function fetchAllRejectsExcludedTablePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader->fetchAll('index_words', 100);
    }
}
