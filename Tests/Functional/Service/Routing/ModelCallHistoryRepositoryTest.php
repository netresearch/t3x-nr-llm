<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Routing;

use Netresearch\NrLlm\Service\Routing\ModelCallHistoryRepository;
use Netresearch\NrLlm\Service\Routing\ModelCallStats;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(ModelCallHistoryRepository::class)]
#[CoversClass(ModelCallStats::class)]
final class ModelCallHistoryRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private ModelCallHistoryRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->repository = new ModelCallHistoryRepository($this->connectionPool);
    }

    #[Test]
    public function averagesLatencyAndCostPerServingModelAndOperation(): void
    {
        $now = time();
        $this->insertRow(['latency_ms' => 1000, 'actual_cost' => '0.00200000', 'crdate' => $now]);
        $this->insertRow(['latency_ms' => 3000, 'actual_cost' => '0.00400000', 'crdate' => $now]);
        // Unpriced: counts for latency, not for cost.
        $this->insertRow(['latency_ms' => 2000, 'actual_cost' => null, 'crdate' => $now]);

        $stats = $this->repository->statsSince($now - 60)[ModelCallStats::key('openai', 'gpt-a', 'chat')] ?? null;

        self::assertInstanceOf(ModelCallStats::class, $stats);
        self::assertSame(3, $stats->sampleCount);
        self::assertEqualsWithDelta(2000.0, $stats->avgLatencyMs, 0.0001);
        self::assertSame(2, $stats->costedCount);
        self::assertEqualsWithDelta(0.003, $stats->avgCostPerCall, 0.0000001);
    }

    #[Test]
    public function keysByTheServingModelNotTheRequestedOne(): void
    {
        $now = time();
        $this->insertRow(['model' => 'requested', 'served_model' => 'answered', 'crdate' => $now]);
        $this->insertRow(['model' => 'requested', 'served_model' => 'other', 'crdate' => $now]);

        $stats = $this->repository->statsSince($now - 60);

        self::assertSame(1, $stats[ModelCallStats::key('openai', 'answered', 'chat')]->sampleCount ?? null);
        self::assertSame(1, $stats[ModelCallStats::key('openai', 'other', 'chat')]->sampleCount ?? null);
        self::assertArrayNotHasKey(ModelCallStats::key('openai', 'requested', 'chat'), $stats);
    }

    #[Test]
    public function separatesOperationsOfTheSameModel(): void
    {
        $now = time();
        $this->insertRow(['operation' => 'chat', 'latency_ms' => 4000, 'crdate' => $now]);
        $this->insertRow(['operation' => 'tools', 'latency_ms' => 30000, 'crdate' => $now]);

        $stats = $this->repository->statsSince($now - 60);

        self::assertEqualsWithDelta(4000.0, $stats[ModelCallStats::key('openai', 'gpt-a', 'chat')]->avgLatencyMs ?? null, 0.0001);
        self::assertEqualsWithDelta(30000.0, $stats[ModelCallStats::key('openai', 'gpt-a', 'tools')]->avgLatencyMs ?? null, 0.0001);
    }

    #[Test]
    public function leavesOutCallsTheModelDidNotAnswerItselfForReal(): void
    {
        $now = time();
        $this->insertRow(['latency_ms' => 1000, 'crdate' => $now]);
        $this->insertRow(['latency_ms' => 99000, 'success' => 0, 'crdate' => $now]);
        $this->insertRow(['latency_ms' => 99000, 'fallback_attempts' => 1, 'crdate' => $now]);
        $this->insertRow(['latency_ms' => 2, 'cache_hit' => 1, 'crdate' => $now]);
        $this->insertRow(['latency_ms' => 99000, 'crdate' => $now - 3600]);

        $stats = $this->repository->statsSince($now - 60)[ModelCallStats::key('openai', 'gpt-a', 'chat')] ?? null;

        self::assertInstanceOf(ModelCallStats::class, $stats);
        self::assertSame(1, $stats->sampleCount, 'a failure, a rescued run, a cache hit and an old row are all left out');
        self::assertEqualsWithDelta(1000.0, $stats->avgLatencyMs, 0.0001);
    }

    #[Test]
    public function ignoresRowsThatNameNoServingModel(): void
    {
        $now = time();
        $this->insertRow(['served_model' => '', 'crdate' => $now]);
        $this->insertRow(['served_provider' => '', 'crdate' => $now]);

        self::assertSame([], $this->repository->statsSince($now - 60));
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private function insertRow(array $overrides): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, array_merge([
            'pid'                             => 0,
            'correlation_id'                  => 'corr-' . uniqid('', true),
            'operation'                       => 'chat',
            'provider'                        => 'openai',
            'model'                           => 'gpt-a',
            'configuration_identifier'        => 'primary',
            'served_configuration_identifier' => 'primary',
            'served_provider'                 => 'openai',
            'served_model'                    => 'gpt-a',
            'be_user'                         => 0,
            'success'                         => 1,
            'error_class'                     => '',
            'latency_ms'                      => 1000,
            'cache_hit'                       => 0,
            'fallback_attempts'               => 0,
            'actual_cost'                     => null,
            'crdate'                          => time(),
        ], $overrides));
    }
}
