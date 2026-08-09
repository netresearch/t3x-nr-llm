<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Analytics;

use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerConfig;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerStoreInterface;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Service\Analytics\ProviderHealthReport;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The readout ADR-063 deferred, against real telemetry rows and the real
 * `nrllm_circuit` cache.
 */
#[CoversClass(ProviderHealthReport::class)]
#[CoversClass(CircuitBreakerConfig::class)]
final class ProviderHealthReportTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    #[Test]
    public function scoreCarriesItsSampleCountAndTheWindowItWasTakenOver(): void
    {
        $this->insertRow('openai', true, 100);
        $this->insertRow('openai', true, 300);
        $this->insertRow('openai', false, 200);

        $readout = $this->report()->readout();

        self::assertSame(900, $readout['windowSeconds']);
        $row = $this->rowFor($readout, 'openai');
        self::assertSame(3, $row['sampleCount']);
        self::assertEqualsWithDelta(2 / 3, $row['successRate'], 0.0001);
        self::assertEqualsWithDelta(200.0, $row['avgLatencyMs'], 0.0001);
        self::assertNotNull($row['score']);
        self::assertEqualsWithDelta(0.7253, $row['score'], 0.0001);
    }

    #[Test]
    public function aProviderWithoutTelemetryHasNoScoreRatherThanAZero(): void
    {
        // ollama is configured and active; nothing called it.
        $this->importFixture('Providers.csv');
        $this->insertRow('openai', true, 100);

        $row = $this->rowFor($this->report()->readout(), 'ollama');

        self::assertSame(0, $row['sampleCount']);
        self::assertNull($row['score'], 'No samples means no score — not the neutral 0.5, not 0.0.');
        self::assertNull($row['successRate']);
        self::assertNull($row['avgLatencyMs']);
    }

    #[Test]
    public function aConfiguredProviderThatWasNeverCalledStillGetsARow(): void
    {
        $this->importFixture('Providers.csv');

        $providers = array_column($this->report()->readout()['providers'], 'provider');

        self::assertContains('ollama', $providers);
        self::assertContains('openai', $providers);
    }

    #[Test]
    public function reorderSwitchIsReportedInBothPositions(): void
    {
        $this->storeNrLlmConfig(['health' => ['reorderFallback' => '0']]);
        self::assertFalse($this->report()->readout()['reorderFallback']);

        $this->storeNrLlmConfig(['health' => ['reorderFallback' => '1']]);
        self::assertTrue($this->report()->readout()['reorderFallback']);
    }

    #[Test]
    public function circuitStateIsReadBackPerProvider(): void
    {
        $this->insertRow('openai', false, 100);
        $this->store()->save('openai', new CircuitState(5, time()), 300);
        $this->store()->save('groq', CircuitState::closed(), 300);

        $readout = $this->report()->readout();

        self::assertSame('open', $this->rowFor($readout, 'openai')['circuit']);
        self::assertSame(5, $this->rowFor($readout, 'openai')['consecutiveFailures']);
        // groq has neither telemetry nor a provider record, so it is not a row
        // at all — an all-closed circuit is not a reason to list a provider.
        self::assertSame([], array_filter(
            $readout['providers'],
            static fn(array $row): bool => $row['provider'] === 'groq',
        ));
    }

    #[Test]
    public function anElapsedCooldownReadsAsHalfOpen(): void
    {
        $this->insertRow('openai', false, 100);
        $this->store()->save('openai', new CircuitState(5, time() - 100), 300);

        // Default cooldown is 30s, so 100s ago is past it: a probe is due.
        self::assertSame('half_open', $this->rowFor($this->report()->readout(), 'openai')['circuit']);
    }

    #[Test]
    public function theConfiguredCooldownDecidesTheStatusNotADefaultOfItsOwn(): void
    {
        // The same state as above, but the operator widened the cooldown. A
        // reader with its own default would call this half-open while the
        // middleware still fails fast on it.
        $this->storeNrLlmConfig(['circuitBreaker' => ['cooldownSeconds' => '600']]);
        $this->insertRow('openai', false, 100);
        $this->store()->save('openai', new CircuitState(5, time() - 100), 900);

        self::assertSame('open', $this->rowFor($this->report()->readout(), 'openai')['circuit']);
    }

    #[Test]
    public function aDisabledCircuitBreakerIsReported(): void
    {
        $this->storeNrLlmConfig(['circuitBreaker' => ['enabled' => '0']]);

        self::assertFalse($this->report()->readout()['circuitBreakerEnabled']);
    }

    /**
     * Write the raw stored nr_llm extension configuration, narrowing the untyped
     * $GLOBALS shape step by step so the writes stay PHPStan-clean.
     *
     * @param array<string, mixed> $nrLlm
     */
    private function storeNrLlmConfig(array $nrLlm): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }

        $extensions['nr_llm']       = $nrLlm;
        $confVars['EXTENSIONS']     = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    private function report(): ProviderHealthReport
    {
        return $this->getService(ProviderHealthReport::class);
    }

    private function store(): CircuitBreakerStoreInterface
    {
        $store = $this->get(CircuitBreakerStoreInterface::class);
        self::assertInstanceOf(CircuitBreakerStoreInterface::class, $store);

        return $store;
    }

    /**
     * @param array{
     *     windowSeconds: int,
     *     reorderFallback: bool,
     *     circuitBreakerEnabled: bool,
     *     providers: list<array{
     *         provider: string,
     *         sampleCount: int,
     *         score: float|null,
     *         successRate: float|null,
     *         avgLatencyMs: float|null,
     *         circuit: string,
     *         consecutiveFailures: int
     *     }>
     * } $readout
     *
     * @return array{
     *     provider: string,
     *     sampleCount: int,
     *     score: float|null,
     *     successRate: float|null,
     *     avgLatencyMs: float|null,
     *     circuit: string,
     *     consecutiveFailures: int
     * }
     */
    private function rowFor(array $readout, string $provider): array
    {
        foreach ($readout['providers'] as $row) {
            if ($row['provider'] === $provider) {
                return $row;
            }
        }

        self::fail(sprintf('No readout row for provider "%s".', $provider));
    }

    private function insertRow(string $provider, bool $success, int $latencyMs): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->insert(self::TABLE, [
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
                'fallback_attempts'        => 0,
                'crdate'                   => time(),
            ]);
    }
}
