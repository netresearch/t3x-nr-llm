<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Telemetry;

use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(TelemetryRepository::class)]
#[CoversClass(TelemetryRecord::class)]
final class TelemetryRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private TelemetryRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        // The repository is private in the container by design; instantiate it
        // directly with the real ConnectionPool.
        $this->repository = new TelemetryRepository($this->connectionPool);
    }

    #[Test]
    public function recordInsertsOneRowWithAllFields(): void
    {
        $this->repository->record(new TelemetryRecord(
            correlationId: 'corr-123',
            operation: 'chat',
            provider: 'openai',
            model: 'gpt-4o',
            configurationIdentifier: 'ad-hoc:chat:openai',
            beUser: 42,
            success: false,
            errorClass: 'RuntimeException',
            latencyMs: 1234,
            cacheHit: true,
            fallbackAttempts: 2,
            servedConfigurationIdentifier: 'sibling',
            servedProvider: 'ollama',
            servedModel: 'llama3.3:70b',
        ));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(['*'], self::TABLE, ['correlation_id' => 'corr-123'])->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('chat', $row['operation']);
        self::assertSame('openai', $row['provider']);
        self::assertSame('gpt-4o', $row['model']);
        self::assertSame('ad-hoc:chat:openai', $row['configuration_identifier']);
        self::assertSame(42, (int)$row['be_user']);
        self::assertSame(0, (int)$row['success']);
        self::assertSame('RuntimeException', $row['error_class']);
        self::assertSame(1234, (int)$row['latency_ms']);
        self::assertSame(1, (int)$row['cache_hit']);
        self::assertSame(2, (int)$row['fallback_attempts']);
        self::assertSame('sibling', $row['served_configuration_identifier']);
        self::assertSame('ollama', $row['served_provider']);
        self::assertSame('llama3.3:70b', $row['served_model']);
        self::assertGreaterThan(0, (int)$row['crdate']);
    }

    #[Test]
    public function recordStoresBooleansAsZeroOrOne(): void
    {
        $this->repository->record(new TelemetryRecord(
            correlationId: 'corr-ok',
            operation: 'embed',
            provider: '',
            model: '',
            configurationIdentifier: 'primary',
            beUser: 0,
            success: true,
            errorClass: '',
            latencyMs: 5,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: '',
            servedModel: '',
        ));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(['success', 'cache_hit'], self::TABLE, ['correlation_id' => 'corr-ok'])->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(1, (int)$row['success']);
        self::assertSame(0, (int)$row['cache_hit']);
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyOlderRows(): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        // An old row (10 days ago) inserted directly with a controlled crdate...
        $tenDaysAgo = time() - (10 * 86400);
        $connection->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => 'old-row',
            'operation'                => 'chat',
            'provider'                 => '',
            'model'                    => '',
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'success'                  => 1,
            'error_class'              => '',
            'latency_ms'               => 1,
            'cache_hit'                => 0,
            'fallback_attempts'        => 0,
            'crdate'                   => $tenDaysAgo,
        ]);

        // ...and a fresh row via the repository (crdate = now).
        $this->repository->record(new TelemetryRecord(
            correlationId: 'fresh-row',
            operation: 'chat',
            provider: '',
            model: '',
            configurationIdentifier: 'primary',
            beUser: 0,
            success: true,
            errorClass: '',
            latencyMs: 1,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: '',
            servedModel: '',
        ));

        // Purge everything older than 5 days: removes the old row, keeps the fresh one.
        $cutoff  = time() - (5 * 86400);
        $deleted = $this->repository->purgeOlderThan($cutoff);

        self::assertSame(1, $deleted);
        self::assertSame(0, $connection->count('*', self::TABLE, ['correlation_id' => 'old-row']));
        self::assertSame(1, $connection->count('*', self::TABLE, ['correlation_id' => 'fresh-row']));
    }

    #[Test]
    public function purgeOlderThanReturnsZeroWhenNothingMatches(): void
    {
        $this->repository->record(new TelemetryRecord(
            correlationId: 'fresh-only',
            operation: 'chat',
            provider: '',
            model: '',
            configurationIdentifier: 'primary',
            beUser: 0,
            success: true,
            errorClass: '',
            latencyMs: 1,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: '',
            servedModel: '',
        ));

        // Cutoff far in the past: the only row is newer, so nothing is deleted.
        $deleted = $this->repository->purgeOlderThan(time() - (365 * 86400));

        self::assertSame(0, $deleted);
    }

    #[Test]
    public function successRatePercentRoundsTheShareOfSucceededRunsInWindow(): void
    {
        $now = time();
        // 3 of 4 in-window rows succeeded ⇒ 75%.
        $this->insertRow('a', true, 100, $now);
        $this->insertRow('b', true, 100, $now);
        $this->insertRow('c', true, 100, $now);
        $this->insertRow('d', false, 100, $now);
        // An out-of-window failure must not drag the rate down.
        $this->insertRow('old', false, 100, $now - (10 * 86400));

        self::assertSame(75, $this->repository->successRatePercent($now - (5 * 86400)));
    }

    #[Test]
    public function successRatePercentIsZeroWhenNoRowsInWindow(): void
    {
        self::assertSame(0, $this->repository->successRatePercent(time() - 60));
    }

    #[Test]
    public function averageLatencyMsRoundsTheMeanLatencyInWindow(): void
    {
        $now = time();
        // Mean of 100, 200, 300 = 200.
        $this->insertRow('a', true, 100, $now);
        $this->insertRow('b', true, 200, $now);
        $this->insertRow('c', true, 300, $now);
        // An out-of-window row must not skew the mean.
        $this->insertRow('old', true, 9000, $now - (10 * 86400));

        self::assertSame(200, $this->repository->averageLatencyMs($now - (5 * 86400)));
    }

    #[Test]
    public function averageLatencyMsIsZeroWhenNoRowsInWindow(): void
    {
        self::assertSame(0, $this->repository->averageLatencyMs(time() - 60));
    }

    #[Test]
    public function recentFallbackHopsSkipsRunsNoSiblingServed(): void
    {
        $now = time();
        // A chain that was tried and exhausted: hops happened, the row still
        // names the requested configuration as the serving one.
        $this->insertHop('exhausted', 'primary', 'primary', $now);
        // Written before the served_* columns existed: unknown, not a swap.
        $this->insertHop('legacy', 'primary', '', $now);
        // The one real rescue.
        $this->insertHop('rescued', 'primary', 'sibling', $now);

        $hops = $this->repository->recentFallbackHops($now - 3600, 200);

        self::assertCount(1, $hops);
        self::assertSame('corr-rescued', $hops[0]->correlationId);
        self::assertSame('sibling', $hops[0]->servedConfigurationIdentifier);
    }

    #[Test]
    public function recentFallbackHopsCapsRescuesRatherThanFallbackAttempts(): void
    {
        $now = time();
        // An outage: three newer rows that hopped and ended with nobody
        // serving. They exceed the limit on their own.
        $this->insertHop('outage-1', 'primary', 'primary', $now);
        $this->insertHop('outage-2', 'primary', 'primary', $now - 1);
        $this->insertHop('outage-3', 'primary', 'primary', $now - 2);
        // An older, genuine rescue behind them.
        $this->insertHop('rescued', 'primary', 'sibling', $now - 10);

        $hops = $this->repository->recentFallbackHops($now - 3600, 2);

        // Capping fallback attempts instead of rescues would return the two
        // newest outage rows here and report the period as rescue-free.
        self::assertCount(1, $hops);
        self::assertSame('corr-rescued', $hops[0]->correlationId);
    }

    private function insertHop(string $correlationId, string $requested, string $served, int $crdate): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                             => 0,
            'correlation_id'                  => 'corr-' . $correlationId,
            'operation'                       => 'chat',
            'provider'                        => 'openai',
            'model'                           => 'gpt-5',
            'configuration_identifier'        => $requested,
            'served_configuration_identifier' => $served,
            'served_provider'                 => $served === '' ? '' : 'ollama',
            'served_model'                    => $served === '' ? '' : 'llama3.3:70b',
            'be_user'                         => 0,
            'success'                         => 1,
            'error_class'                     => '',
            'latency_ms'                      => 100,
            'cache_hit'                       => 0,
            'fallback_attempts'               => 2,
            'crdate'                          => $crdate,
        ]);
    }

    private function insertRow(string $correlationId, bool $success, int $latencyMs, int $crdate): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => $correlationId,
            'operation'                => 'chat',
            'provider'                 => '',
            'model'                    => '',
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'success'                  => $success ? 1 : 0,
            'error_class'              => '',
            'latency_ms'               => $latencyMs,
            'cache_hit'                => 0,
            'fallback_attempts'        => 0,
            'crdate'                   => $crdate,
        ]);
    }
}
