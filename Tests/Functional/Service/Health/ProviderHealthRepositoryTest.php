<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Health;

use Netresearch\NrLlm\Service\Health\ProviderHealthRepository;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(ProviderHealthRepository::class)]
#[CoversClass(ProviderHealthScore::class)]
final class ProviderHealthRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private ProviderHealthRepository $repository;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->repository = new ProviderHealthRepository($this->connectionPool);
    }

    #[Test]
    public function aggregatesSuccessRateAndLatencyPerProvider(): void
    {
        $now = time();
        $this->insertRow('openai', true, 100, $now);
        $this->insertRow('openai', true, 300, $now);
        $this->insertRow('openai', false, 200, $now);

        $scores = $this->repository->scoresSince($now - 900);

        self::assertArrayHasKey('openai', $scores);
        $openai = $scores['openai'];
        self::assertSame(3, $openai->sampleCount);
        self::assertEqualsWithDelta(2 / 3, $openai->successRate, 0.0001);
        self::assertEqualsWithDelta(200.0, $openai->avgLatencyMs, 0.0001);
    }

    #[Test]
    public function excludesRowsOlderThanTheWindow(): void
    {
        $now = time();
        $this->insertRow('groq', true, 50, $now - 100_000); // outside a 900s window

        $scores = $this->repository->scoresSince($now - 900);

        self::assertArrayNotHasKey('groq', $scores);
    }

    #[Test]
    public function ignoresRowsWithoutAProvider(): void
    {
        $now = time();
        // Ad-hoc direct calls record an empty provider — not a health signal.
        $this->insertRow('', true, 10, $now);

        $scores = $this->repository->scoresSince($now - 900);

        self::assertArrayNotHasKey('', $scores);
    }

    #[Test]
    public function ignoresRunsServedByAFallback(): void
    {
        $now = time();
        // openai failed serving the request itself (fallback_attempts = 0) ...
        $this->insertRow('openai', false, 200, $now, 0);
        // ... and a run where openai was the requested PRIMARY but a fallback
        // rescued it (success = 1, fallback_attempts = 2). The success belongs
        // to the fallback, not to openai — it must not credit openai's health.
        $this->insertRow('openai', true, 150, $now, 2);

        $scores = $this->repository->scoresSince($now - 900);

        self::assertArrayHasKey('openai', $scores);
        self::assertSame(1, $scores['openai']->sampleCount, 'Only the self-served run counts');
        self::assertSame(0.0, $scores['openai']->successRate);
    }

    #[Test]
    public function separatesProvidersIntoDistinctScores(): void
    {
        $now = time();
        $this->insertRow('openai', true, 100, $now);
        $this->insertRow('claude', false, 400, $now);

        $scores = $this->repository->scoresSince($now - 900);

        self::assertArrayHasKey('openai', $scores);
        self::assertArrayHasKey('claude', $scores);
        self::assertSame(1.0, $scores['openai']->successRate);
        self::assertSame(0.0, $scores['claude']->successRate);
    }

    private function insertRow(string $provider, bool $success, int $latencyMs, int $crdate, int $fallbackAttempts = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => 'corr-' . uniqid('', true),
            'operation'                => 'chat',
            'provider'                 => $provider,
            'model'                    => '',
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'success'                  => $success ? 1 : 0,
            'error_class'              => '',
            'latency_ms'               => $latencyMs,
            'cache_hit'                => 0,
            'fallback_attempts'        => $fallbackAttempts,
            'crdate'                   => $crdate,
        ]);
    }
}
