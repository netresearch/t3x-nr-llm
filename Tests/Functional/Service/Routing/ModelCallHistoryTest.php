<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Routing;

use Netresearch\NrLlm\Service\Routing\ModelCallHistory;
use Netresearch\NrLlm\Service\Routing\ModelCallHistoryRepository;
use Netresearch\NrLlm\Service\Routing\ModelCallStats;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(ModelCallHistory::class)]
final class ModelCallHistoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    protected function setUp(): void
    {
        parent::setUp();

        // An aggregate cached by an earlier test would answer for this one.
        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        $cacheManager->getCache('nrllm_health')->flush();
    }

    #[Test]
    public function readsTheAggregateOnceAndServesTheNextDecisionFromTheCache(): void
    {
        $this->insertRow(1000);
        $history = $this->history(['routing' => ['callHistory' => '1']]);

        $key = ModelCallStats::key('openai', 'gpt-a', 'chat');
        self::assertSame(1, $history->all()[$key]->sampleCount ?? null);

        // A call recorded after the aggregate was taken is not seen until the
        // cache entry expires: the decision reads the cache, not the table.
        $this->insertRow(3000);

        self::assertSame(1, $history->all()[$key]->sampleCount ?? null);
    }

    #[Test]
    public function readsTheLastSevenDays(): void
    {
        $this->insertRow(1000, time() - 6 * 86400);
        $this->insertRow(1000, time() - 8 * 86400);

        self::assertSame(1, $this->history([])->all()[ModelCallStats::key('openai', 'gpt-a', 'chat')]->sampleCount ?? null);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('settings')]
    public function isOffUnlessTheOperatorSwitchedItOn(array $config, bool $expected): void
    {
        self::assertSame($expected, $this->history($config)->enabled());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function settings(): iterable
    {
        yield 'no routing section' => [[], false];
        yield 'routing without the key' => [['routing' => ['policyMode' => 'balanced']], false];
        yield 'off' => [['routing' => ['callHistory' => '0']], false];
        yield 'on' => [['routing' => ['callHistory' => '1']], true];
        yield 'malformed section' => [['routing' => 'yes'], false];
    }

    #[Test]
    public function anUnreadableConfigurationIsOff(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('unreadable'));

        self::assertFalse($this->historyWith($configuration)->enabled());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function history(array $config): ModelCallHistory
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($config);

        return $this->historyWith($configuration);
    }

    private function historyWith(ExtensionConfiguration $configuration): ModelCallHistory
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);

        return new ModelCallHistory(new ModelCallHistoryRepository($connectionPool), $cacheManager, $configuration);
    }

    private function insertRow(int $latencyMs, ?int $crdate = null): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'               => 0,
            'correlation_id'    => 'corr-' . uniqid('', true),
            'operation'         => 'chat',
            'provider'          => 'openai',
            'served_provider'   => 'openai',
            'served_model'      => 'gpt-a',
            'success'           => 1,
            'latency_ms'        => $latencyMs,
            'cache_hit'         => 0,
            'fallback_attempts' => 0,
            'crdate'            => $crdate ?? time(),
        ]);
    }
}
