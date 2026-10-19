<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitStatus;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryCircuitBreakerStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use stdClass;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(CircuitBreakerMiddleware::class)]
final class CircuitBreakerMiddlewareTest extends AbstractUnitTestCase
{
    private const PROVIDER = 'openai';

    #[Test]
    public function passesThroughWhenDisabled(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        // Even a wide-open circuit must be ignored when the breaker is off.
        $store->seed(self::PROVIDER, new CircuitState(9, time()));

        $result = $this->middleware($store, enabled: false)->handle(
            $this->context()->withConfiguration($this->config(self::PROVIDER)),
            static fn(): string => 'ok',
        );

        self::assertSame('ok', $result);
        self::assertSame([], $store->saves);
    }

    #[Test]
    public function passesThroughWhenConfigurationHasNoCircuitKey(): void
    {
        $store  = new InMemoryCircuitBreakerStore();
        $called = false;

        $result = $this->middleware($store)->handle(
            $this->context()->withConfiguration(new LlmConfiguration()), // no identifier, no model → empty key
            static function () use (&$called): string {
                $called = true;

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertTrue($called);
        self::assertSame([], $store->saves);
    }

    #[Test]
    public function closedCircuitSuccessDoesNotWriteState(): void
    {
        $store = new InMemoryCircuitBreakerStore();

        $result = $this->middleware($store)->handle(
            $this->context()->withConfiguration($this->config(self::PROVIDER)),
            static fn(): string => 'ok',
        );

        self::assertSame('ok', $result);
        // Hot path: a healthy closed circuit performs no redundant cache write.
        self::assertSame([], $store->saves);
    }

    #[Test]
    public function opensAfterThresholdConsecutiveFailures(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $middleware = $this->middleware($store, threshold: 3, cooldown: 30);

        for ($i = 0; $i < 3; $i++) {
            try {
                $middleware->handle(
                    $this->context()->withConfiguration($this->config(self::PROVIDER)),
                    static fn(): never => throw new ProviderConnectionException('down', 0),
                );
                self::fail('Expected the connection failure to propagate');
            } catch (ProviderConnectionException) {
                // expected — the breaker records and re-throws
            }
        }

        $state = $store->load(self::PROVIDER);
        self::assertSame(3, $state->consecutiveFailures);
        self::assertNotNull($state->openedAt, 'Circuit must be open after the threshold is reached');
        // Cooldown 30 → lifetime max(60, 60) = 60.
        $lastSave = end($store->saves);
        self::assertIsArray($lastSave);
        self::assertSame(60, $lastSave['lifetime']);
    }

    #[Test]
    public function belowThresholdCircuitStaysClosed(): void
    {
        $store = new InMemoryCircuitBreakerStore();

        try {
            $this->middleware($store, threshold: 3)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new ProviderConnectionException('down', 0),
            );
        } catch (ProviderConnectionException) {
        }

        $state = $store->load(self::PROVIDER);
        self::assertSame(1, $state->consecutiveFailures);
        self::assertNull($state->openedAt);
    }

    #[Test]
    public function aServerErrorCountsTowardsTripping(): void
    {
        // New with ADR-095: a 5xx is a provider-side fault and now counts
        // towards opening the circuit. Before, only connection and 429 did, so
        // a provider returning 500 repeatedly never tripped.
        $store = new InMemoryCircuitBreakerStore();

        try {
            $this->middleware($store, threshold: 3)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new ProviderResponseException('boom', 500),
            );
        } catch (ProviderResponseException) {
        }

        self::assertSame(1, $store->load(self::PROVIDER)->consecutiveFailures);
    }

    #[Test]
    public function aClientErrorDoesNotCountTowardsTripping(): void
    {
        // A 4xx is our fault, not the provider's — it must not open the circuit.
        $store = new InMemoryCircuitBreakerStore();

        try {
            $this->middleware($store, threshold: 3)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new ProviderResponseException('bad request', 400),
            );
        } catch (ProviderResponseException) {
        }

        self::assertSame(0, $store->load(self::PROVIDER)->consecutiveFailures);
    }

    #[Test]
    public function openCircuitFailsFastWithoutCallingProvider(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(self::PROVIDER, new CircuitState(5, time()));
        // opened just now
        $called = false;

        try {
            $this->middleware($store, cooldown: 30)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static function () use (&$called): string {
                    $called = true;

                    return 'ok';
                },
            );
            self::fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            self::assertSame(self::PROVIDER, $e->provider);
            self::assertGreaterThan(0, $e->retryAfterSeconds);
        }

        self::assertFalse($called, 'An open circuit must not reach the provider');
    }

    #[Test]
    public function halfOpenProbeSuccessClosesCircuit(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        // Opened long enough ago that the cooldown has elapsed → half-open.
        $store->seed(self::PROVIDER, new CircuitState(5, time() - 31));

        $called = false;

        $result = $this->middleware($store, cooldown: 30)->handle(
            $this->context()->withConfiguration($this->config(self::PROVIDER)),
            static function () use (&$called): string {
                $called = true;

                return 'recovered';
            },
        );

        self::assertSame('recovered', $result);
        self::assertTrue($called, 'A half-open probe must reach the provider');

        $state = $store->load(self::PROVIDER);
        self::assertSame(0, $state->consecutiveFailures);
        self::assertNull($state->openedAt, 'A successful probe closes the circuit');
    }

    #[Test]
    public function halfOpenProbeFailureReopensCircuit(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(self::PROVIDER, new CircuitState(5, time() - 31)); // half-open

        try {
            $this->middleware($store, cooldown: 30)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new ProviderConnectionException('still down', 0),
            );
        } catch (ProviderConnectionException) {
        }

        $state = $store->load(self::PROVIDER);
        self::assertSame(6, $state->consecutiveFailures);
        self::assertNotNull($state->openedAt);
        // Fresh window: reopened at ~now, not the original open time.
        self::assertGreaterThan(time() - 5, $state->openedAt);
    }

    #[Test]
    public function halfOpenProbeNonTrippingFailureKeepsCircuitHalfOpen(): void
    {
        $store    = new InMemoryCircuitBreakerStore();
        $openedAt = time() - 31; // cooldown 30 elapsed → half-open
        $store->seed(self::PROVIDER, new CircuitState(5, $openedAt));

        try {
            $this->middleware($store, cooldown: 30)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                // 401: the provider responded, so it is not a health signal.
                static fn(): never => throw new ProviderResponseException('unauthorized', 401),
            );
            self::fail('Expected the response exception to propagate');
        } catch (ProviderResponseException) {
            // expected
        }

        // The probe reservation (openedAt = now) must be rolled back, so the
        // circuit stays half-open (a further probe is due) instead of re-arming
        // Open for a fresh cooldown — otherwise recurring non-tripping probes
        // would starve recovery.
        $state = $store->load(self::PROVIDER);
        self::assertSame($openedAt, $state->openedAt, 'The pre-probe open window must be restored');
        self::assertSame(5, $state->consecutiveFailures);
        self::assertSame(CircuitStatus::HalfOpen, $state->status(time(), 30));
    }

    #[Test]
    public function successResetsAFailureStreak(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(self::PROVIDER, new CircuitState(2)); // closed, counting

        $this->middleware($store)->handle(
            $this->context()->withConfiguration($this->config(self::PROVIDER)),
            static fn(): string => 'ok',
        );

        $state = $store->load(self::PROVIDER);
        self::assertSame(0, $state->consecutiveFailures);
        self::assertNull($state->openedAt);
    }

    #[Test]
    public function nonTrippingFailureLeavesStateUntouchedAndRethrows(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(self::PROVIDER, new CircuitState(2));

        try {
            $this->middleware($store)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                // 401 is a client error: the provider answered, so it is not a
                // health signal — neither trip nor reset.
                static fn(): never => throw new ProviderResponseException('unauthorized', 401),
            );
            self::fail('Expected the response exception to propagate');
        } catch (ProviderResponseException $e) {
            self::assertSame(401, $e->getCode());
        }

        self::assertSame([], $store->saves, 'A client error must not touch the circuit');
        self::assertSame(2, $store->load(self::PROVIDER)->consecutiveFailures);
    }

    #[Test]
    public function rateLimitCountsAsTrippingFailure(): void
    {
        $store = new InMemoryCircuitBreakerStore();

        try {
            $this->middleware($store, threshold: 1)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new ProviderResponseException('slow down', 429),
            );
        } catch (ProviderResponseException) {
        }

        $state = $store->load(self::PROVIDER);
        self::assertSame(1, $state->consecutiveFailures);
        self::assertNotNull($state->openedAt, 'A 429 trips the circuit like a connection failure');
    }

    #[Test]
    public function nonProviderExceptionDoesNotTripTheCircuit(): void
    {
        $store = new InMemoryCircuitBreakerStore();

        try {
            $this->middleware($store, threshold: 1)->handle(
                $this->context()->withConfiguration($this->config(self::PROVIDER)),
                static fn(): never => throw new RuntimeException('bug', 1),
            );
        } catch (RuntimeException) {
        }

        self::assertSame([], $store->saves);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function middleware(
        InMemoryCircuitBreakerStore $store,
        bool $enabled = true,
        int $threshold = 3,
        int $cooldown = 30,
    ): CircuitBreakerMiddleware {
        return new CircuitBreakerMiddleware(
            $store,
            $this->extensionConfiguration($enabled, $threshold, $cooldown),
        );
    }

    private function extensionConfiguration(bool $enabled, int $threshold, int $cooldown): ExtensionConfiguration&MockObject
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'circuitBreaker' => [
                'enabled'          => $enabled ? '1' : '0',
                'failureThreshold' => $threshold,
                'cooldownSeconds'  => $cooldown,
            ],
        ]);

        return $extensionConfiguration;
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Chat, 'corr');
    }

    private function config(string $identifier): LlmConfiguration
    {
        // A model-less configuration has an empty provider type, so the circuit
        // keys by identifier — which is exactly what the ad-hoc path relies on.
        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);

        return $config;
    }

    #[Test]
    public function aLargePositiveCooldownPreservesSuccessfulProviderAnswers(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(self::PROVIDER, new CircuitState(2));

        $answer = new stdClass();
        $actual = null;
        $caught = null;
        $calls = 0;
        try {
            $actual = $this
                ->middleware($store, cooldown: PHP_INT_MAX)
                ->handle(
                    $this->context()->withConfiguration(
                        $this->config(self::PROVIDER),
                    ),
                    static function () use ($answer, &$calls): object {
                        $calls++;
                        return $answer;
                    },
                );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'A supported positive cooldown must not replace the provider answer.',
        );
        self::assertSame($answer, $actual);
        self::assertSame(1, $calls);
        self::assertSame(0, $store->load(self::PROVIDER)->consecutiveFailures);
        self::assertSame(PHP_INT_MAX, $store->saves[0]['lifetime']);
    }

    #[Test]
    public function aLargePositiveCooldownPreservesTheOriginalProviderFailure(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $expected = new ProviderResponseException('gateway failed', 503);
        $caught = null;
        $calls = 0;
        try {
            $this
                ->middleware($store, threshold: 1, cooldown: PHP_INT_MAX)
                ->handle(
                    $this->context()->withConfiguration(
                        $this->config(self::PROVIDER),
                    ),
                    static function () use ($expected, &$calls): never {
                        $calls++;
                        throw $expected;
                    },
                );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame(
            $expected,
            $caught,
            'State lifetime arithmetic must not replace the original provider failure.',
        );
        self::assertSame(1, $calls);
        self::assertSame(1, $store->load(self::PROVIDER)->consecutiveFailures);
        self::assertNotNull($store->load(self::PROVIDER)->openedAt);
        self::assertSame(PHP_INT_MAX, $store->saves[0]['lifetime']);
    }

    #[Test]
    public function stateLifetimesKeepExactDoublingUntilTheIntegerBoundary(): void
    {
        $boundary = intdiv(PHP_INT_MAX, 2);
        foreach ([[$boundary, PHP_INT_MAX - 1], [$boundary + 1, PHP_INT_MAX], [31, 62]] as [$cooldown, $expectedLifetime]) {
            $store = new InMemoryCircuitBreakerStore();
            $store->seed(self::PROVIDER, new CircuitState(2));
            $actual = null;
            $caught = null;
            try {
                $actual = $this
                    ->middleware($store, cooldown: $cooldown)
                    ->handle(
                        $this->context()->withConfiguration(
                            $this->config(self::PROVIDER),
                        ),
                        static fn(): string => 'recovered',
                    );
            } catch (Throwable $failure) {
                $caught = $failure;
            }

            self::assertNull($caught);
            self::assertSame('recovered', $actual);
            self::assertCount(1, $store->saves);
            self::assertSame($expectedLifetime, $store->saves[0]['lifetime']);
        }
    }

    #[Test]
    public function anAcceptedMaximumFailureCountPreservesTheOriginalFailureAndOpensTheCircuit(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->seed(
            self::PROVIDER,
            CircuitState::fromArray(
                ['consecutiveFailures' => PHP_INT_MAX, 'openedAt' => null],
            ),
        );
        $expected = new ProviderResponseException('gateway failed', 503);
        $caught = null;
        $calls = 0;
        $middleware = $this->middleware($store);
        $context = $this->context()->withConfiguration($this->config(self::PROVIDER));
        try {
            $middleware->handle(
                $context,
                static function () use ($expected, &$calls): never {
                    $calls++;
                    throw $expected;
                },
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame(
            $expected,
            $caught,
            'Accepted cached counters must not replace the original provider failure.',
        );
        self::assertSame(1, $calls);
        self::assertCount(1, $store->saves);
        self::assertSame(
            PHP_INT_MAX,
            $store->load(self::PROVIDER)->consecutiveFailures,
        );
        self::assertNotNull($store->load(self::PROVIDER)->openedAt);
        $openedFailure = null;
        try {
            $middleware->handle(
                $context,
                static function () use (&$calls): string {
                    $calls++;
                    return 'must not be called';
                },
            );
        } catch (Throwable $failure) {
            $openedFailure = $failure;
        }

        self::assertInstanceOf(CircuitOpenException::class, $openedFailure);
        self::assertSame(1, $calls);
    }
}
