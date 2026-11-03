<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Usage;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\ValueObject\OperationUsage;
use Netresearch\NrLlm\Service\Usage\ExtensionUsageReader;
use Netresearch\NrLlm\Service\Usage\ExtensionUsageReaderInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Every figure comes from the rows of the named extension, in the period, and
 * of the named user when one is given: each fixture carries a row of another
 * extension, another user and one outside the period that must not count.
 */
#[CoversClass(ExtensionUsageReader::class)]
final class ExtensionUsageReaderTest extends AbstractFunctionalTestCase
{
    private const EXT = 't3_cowriter';

    private ExtensionUsageReaderInterface $reader;

    private ConnectionPool $connectionPool;

    private DateTimeImmutable $from;

    private DateTimeImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();
        $reader = $this->get(ExtensionUsageReaderInterface::class);
        self::assertInstanceOf(ExtensionUsageReaderInterface::class, $reader);
        $this->reader = $reader;
        $pool         = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;
        $this->from           = new DateTimeImmutable('2026-06-01 00:00:00');
        $this->to             = new DateTimeImmutable('2026-06-30 23:59:59');
    }

    #[Test]
    public function totalsSumOnlyTheExtensionsRowsInThePeriod(): void
    {
        $this->insertUsage('2026-06-02', self::EXT, 7, ['request_count' => 2, 'tokens_used' => 300, 'estimated_cost' => 0.25]);
        $this->insertUsage('2026-06-20', self::EXT, 8, ['request_count' => 3, 'tokens_used' => 100, 'estimated_cost' => 0.5]);
        $this->insertUsage('2026-06-03', 'nr_mcp_agent', 7, ['request_count' => 50, 'tokens_used' => 9000, 'estimated_cost' => 9.0]);
        $this->insertUsage('2026-05-31', self::EXT, 7, ['request_count' => 40, 'tokens_used' => 4000, 'estimated_cost' => 4.0]);
        $this->insertUsage('2026-07-01', self::EXT, 7, ['request_count' => 30, 'tokens_used' => 3000, 'estimated_cost' => 3.0]);

        $totals = $this->reader->totals(self::EXT, $this->from, $this->to);

        self::assertSame(5, $totals->requests);
        self::assertSame(400, $totals->tokens);
        self::assertEqualsWithDelta(0.75, $totals->estimatedCost, 0.000001);
    }

    #[Test]
    public function totalsForAUserCountOnlyThatUser(): void
    {
        $this->insertUsage('2026-06-02', self::EXT, 7, ['request_count' => 2, 'tokens_used' => 300, 'estimated_cost' => 0.25]);
        $this->insertUsage('2026-06-02', self::EXT, 8, ['request_count' => 3, 'tokens_used' => 100, 'estimated_cost' => 0.5]);

        $totals = $this->reader->totals(self::EXT, $this->from, $this->to, 7);

        self::assertSame(2, $totals->requests);
        self::assertSame(300, $totals->tokens);
        self::assertEqualsWithDelta(0.25, $totals->estimatedCost, 0.000001);
    }

    #[Test]
    public function totalsAreZeroWithoutRows(): void
    {
        $totals = $this->reader->totals(self::EXT, $this->from, $this->to);

        self::assertSame(0, $totals->requests);
        self::assertSame(0, $totals->tokens);
        self::assertSame(0.0, $totals->estimatedCost);
    }

    #[Test]
    public function operationsCountCallsAndFailuresPerOperation(): void
    {
        $this->insertCall('2026-06-02 10:00', self::EXT, 'translate', 7, true);
        $this->insertCall('2026-06-03 10:00', self::EXT, 'translate', 8, false);
        $this->insertCall('2026-06-04 10:00', self::EXT, 'translate', 7, true);
        $this->insertCall('2026-06-05 10:00', self::EXT, 'altText', 7, false);
        $this->insertCall('2026-06-05 10:00', 'nr_mcp_agent', 'translate', 7, false);
        $this->insertCall('2026-05-31 23:00', self::EXT, 'translate', 7, false);
        $this->insertCall('2026-07-01 00:30', self::EXT, 'altText', 7, true);

        $operations = $this->reader->operations(self::EXT, $this->from, $this->to);

        self::assertEquals([
            new OperationUsage('translate', 3, 1, (new DateTimeImmutable('2026-06-04 10:00'))->getTimestamp()),
            new OperationUsage('altText', 1, 1, (new DateTimeImmutable('2026-06-05 10:00'))->getTimestamp()),
        ], $operations);
    }

    #[Test]
    public function operationsForAUserCountOnlyThatUser(): void
    {
        $this->insertCall('2026-06-02 10:00', self::EXT, 'translate', 7, true);
        $this->insertCall('2026-06-03 10:00', self::EXT, 'translate', 8, false);

        $operations = $this->reader->operations(self::EXT, $this->from, $this->to, 8);

        self::assertEquals([
            new OperationUsage('translate', 1, 1, (new DateTimeImmutable('2026-06-03 10:00'))->getTimestamp()),
        ], $operations);
    }

    #[Test]
    public function coverageNamesTheOldestStoredCall(): void
    {
        $this->insertCall('2026-06-03 10:00', 'nr_mcp_agent', 'chatTurn', 7, true);
        $this->insertCall('2026-06-02 09:00', self::EXT, 'translate', 7, true);

        $coverage = $this->reader->coverage();

        self::assertTrue($coverage->enabled);
        self::assertSame(30, $coverage->retentionDays);
        self::assertSame((new DateTimeImmutable('2026-06-02 09:00'))->getTimestamp(), $coverage->oldestCallAt);
    }

    #[Test]
    public function coverageWithoutCallsHasNoOldestCall(): void
    {
        self::assertNull($this->reader->coverage()->oldestCallAt);
    }

    /**
     * @param array<string, int|float|string> $overrides
     */
    private function insertUsage(string $date, string $extension, int $beUser, array $overrides): void
    {
        $ts = (new DateTimeImmutable($date . ' 00:00:00'))->getTimestamp();
        $this->connectionPool->getConnectionForTable('tx_nrllm_service_usage')->insert('tx_nrllm_service_usage', array_merge([
            'pid'              => 0,
            'service_type'     => 'chat',
            'service_provider' => 'openai',
            'model_id'         => 'gpt-4o',
            'source_extension' => $extension,
            'be_user'          => $beUser,
            'request_count'    => 1,
            'tokens_used'      => 10,
            'estimated_cost'   => 0.01,
            'request_date'     => $ts,
            'tstamp'           => $ts,
            'crdate'           => $ts,
        ], $overrides));
    }

    private function insertCall(string $time, string $extension, string $operation, int $beUser, bool $success): void
    {
        $ts = (new DateTimeImmutable($time))->getTimestamp();
        $this->connectionPool->getConnectionForTable('tx_nrllm_telemetry')->insert('tx_nrllm_telemetry', [
            'pid'              => 0,
            'operation'        => 'chat',
            'provider'         => 'openai',
            'be_user'          => $beUser,
            'source_extension' => $extension,
            'source_operation' => $operation,
            'success'          => $success ? 1 : 0,
            'crdate'           => $ts,
        ]);
    }
}
