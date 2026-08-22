<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Command;

use Netresearch\NrLlm\Command\SeedDemoDataCommand;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The demo seed against a real database.
 *
 * Functional rather than unit because every property worth asserting is about
 * what lands in the database: that the relations resolve, that a second run
 * does not duplicate, and that the usage history is reproducible. A doubled
 * connection would assert the command's intent rather than its effect.
 *
 * This test is also the evidence for a claim the command's own docblock makes —
 * that moving the seed out of `.ddev/` made it usable by the test suite. It
 * runs on whatever DBMS the suite runs on, which is SQLite by default and the
 * one thing the MySQL dumps this replaces could never do.
 */
#[CoversClass(SeedDemoDataCommand::class)]
final class SeedDemoDataCommandTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->get(ConnectionPool::class);
    }

    #[Test]
    public function seedsTheRecordGraphWithTheCountsTheDataFileDeclares(): void
    {
        $this->runCommand(['--days' => '0']);

        // Re-derived from the shipped data rather than repeated here: a
        // hard-coded 13 would have to be edited every time a task is added,
        // and an assertion nobody updates gets deleted rather than fixed.
        /** @var array{providers: list<array<string, mixed>>, models: list<array<string, mixed>>, configurations: list<array<string, mixed>>, tasks: list<array<string, mixed>>} $data */
        $data = require dirname(__DIR__, 3) . '/Resources/Private/Demo/DemoData.php';

        self::assertSame(count($data['providers']), $this->countRows('tx_nrllm_provider'));
        self::assertSame(count($data['models']), $this->countRows('tx_nrllm_model'));
        self::assertSame(count($data['configurations']), $this->countRows('tx_nrllm_configuration'));
        self::assertSame(count($data['tasks']), $this->countRows('tx_nrllm_task'));
    }

    #[Test]
    public function resolvesEveryRelationToARealUid(): void
    {
        $this->runCommand(['--days' => '0']);

        $providerUid = $this->uidOf('tx_nrllm_provider', 'ollama-local');
        $modelUid    = $this->uidOf('tx_nrllm_model', 'qwen3-4b');
        $configUid   = $this->uidOf('tx_nrllm_configuration', 'local-general');

        self::assertGreaterThan(0, $providerUid);

        // The failure this guards against is silent: an unresolved relation
        // writes 0, and a 0 renders in the backend as "no provider selected"
        // rather than as an error.
        self::assertSame($providerUid, $this->columnOf('tx_nrllm_model', 'qwen3-4b', 'provider_uid'));
        self::assertSame($modelUid, $this->columnOf('tx_nrllm_configuration', 'local-general', 'model_uid'));
        self::assertSame($configUid, $this->columnOf('tx_nrllm_task', 'analyze-syslog', 'configuration_uid'));

        foreach (['tx_nrllm_model' => 'provider_uid', 'tx_nrllm_configuration' => 'model_uid', 'tx_nrllm_task' => 'configuration_uid'] as $table => $column) {
            self::assertSame(
                0,
                $this->countRows($table, [$column => 0]),
                sprintf('%s has rows with an unresolved %s.', $table, $column),
            );
        }
    }

    #[Test]
    public function aSecondRunUpdatesRatherThanDuplicates(): void
    {
        $this->runCommand(['--days' => '0']);
        $first = $this->graphCounts();

        $this->runCommand(['--days' => '0']);

        self::assertSame($first, $this->graphCounts(), 'Re-seeding duplicated rows instead of updating them.');
    }

    #[Test]
    public function theUsageHistoryIsReproducible(): void
    {
        $this->runCommand(['--days' => '30']);
        $first = $this->usageTotals();

        self::assertGreaterThan(0, $first['rows'], 'No usage rows were written.');
        self::assertGreaterThan(0.0, $first['cost'], 'Usage was written but priced at zero.');

        $this->runCommand(['--days' => '30']);

        // The point of the fixed seed: a documentation screenshot taken today
        // and one taken next month show the same figures, so a difference
        // between two screenshots is a real change.
        self::assertSame($first, $this->usageTotals(), 'The same --days produced different history.');
    }

    #[Test]
    public function daysZeroWritesNoUsageAtAll(): void
    {
        $this->runCommand(['--days' => '0']);

        self::assertSame(0, $this->countRows('tx_nrllm_service_usage'));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function runCommand(array $parameters): void
    {
        $tester = new CommandTester($this->get(SeedDemoDataCommand::class));
        $status = $tester->execute($parameters);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
    }

    /**
     * @return array<string, int>
     */
    private function graphCounts(): array
    {
        $counts = [];
        foreach (['tx_nrllm_provider', 'tx_nrllm_model', 'tx_nrllm_configuration', 'tx_nrllm_task'] as $table) {
            $counts[$table] = $this->countRows($table);
        }

        return $counts;
    }

    /**
     * @return array{rows: int, requests: int, cost: float}
     */
    private function usageTotals(): array
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_service_usage');
        $row = $qb->selectLiteral(
            'COUNT(*) AS rows_total',
            'SUM(request_count) AS requests_total',
            'SUM(estimated_cost) AS cost_total',
        )->from('tx_nrllm_service_usage')->executeQuery()->fetchAssociative();

        assert(is_array($row));

        $cost = $row['cost_total'] ?? 0;

        return [
            'rows'     => (int)($row['rows_total'] ?? 0),
            'requests' => (int)($row['requests_total'] ?? 0),
            // Rounded: SQLite and MariaDB disagree in the last bits of a summed
            // DECIMAL, and this asserts reproducibility, not float equality.
            // is_numeric rather than a cast: a summed DECIMAL is mixed at
            // level 10, and casting mixed is what the analyser refuses.
            'cost'     => round(is_numeric($cost) ? (float)$cost : 0.0, 4),
        ];
    }

    /**
     * @param array<string, int|string> $where
     */
    private function countRows(string $table, array $where = []): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->count('uid')->from($table);
        foreach ($where as $column => $value) {
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
        }

        return (int)$qb->executeQuery()->fetchOne();
    }

    private function uidOf(string $table, string $identifier): int
    {
        return $this->columnOf($table, $identifier, 'uid');
    }

    private function columnOf(string $table, string $identifier, string $column): int
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable($table);
        $val = $qb->select($column)
            ->from($table)
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();

        return (int)$val;
    }
}
