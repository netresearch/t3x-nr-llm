<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\DependencyInjection;

use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Telemetry\ProviderRetryCounter;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Pins the production wiring of the retry counter (ADR-174).
 *
 * ``provider_retries`` is a difference of a shared tally: the adapters
 * increment it and the two telemetry write sites difference it. That only
 * measures anything if all three constructors receive the SAME instance, and
 * every one of the three arguments is optional with a private fallback —
 * {@see ProviderAdapterRegistry} takes ``?ProviderRetryCounter $retryCounter =
 * null`` behind a non-autowirable ``array $adapterOverrides = []``, while the
 * two write sites default to a fresh ``new ProviderRetryCounter()``. Those
 * defaults are deliberate (a hand-constructed object reports no retries rather
 * than failing to build), and they are also what makes the failure silent: any
 * ``arguments:`` entry in Services.yaml or a constructor reorder would leave
 * the registry's counter null and the middleware's isolated, and every
 * non-streamed row would then report a measured ``0`` with no test failing.
 *
 * Reflection rather than behaviour, because the identity IS the contract: two
 * counters give the same answer as one for every call that never retries, so
 * an end-to-end assertion would pass on the broken wiring.
 *
 * Extends {@see FunctionalTestCase} directly rather than the project base,
 * whose setUp() turns a container-compile failure into a skipped test — same
 * rationale and the same scoped HashService suppression as
 * {@see ToolLoopGateWiringTest}.
 */
final class RetryCounterWiringTest extends FunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    protected function setUp(): void
    {
        set_error_handler(
            static fn(int $errno, string $errstr, string $errfile): bool => str_contains($errfile, 'Crypto/HashService.php')
                && (str_contains($errstr, 'TYPO3_CONF_VARS') || str_contains($errstr, 'array offset')),
            \E_WARNING,
        );

        try {
            parent::setUp();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function theCounterIsASingletonInTheContainer(): void
    {
        self::assertSame(
            $this->get(ProviderRetryCounter::class),
            $this->get(ProviderRetryCounter::class),
        );
    }

    /**
     * The producer and both consumers hold one tally. Named individually so a
     * failure says WHICH of the three drifted.
     */
    #[Test]
    public function theAdapterRegistryReceivesTheSharedCounter(): void
    {
        self::assertSame($this->sharedCounter(), $this->counterOf($this->get(ProviderAdapterRegistry::class)));
    }

    #[Test]
    public function theTelemetryMiddlewareReceivesTheSharedCounter(): void
    {
        self::assertSame($this->sharedCounter(), $this->counterOf($this->get(TelemetryMiddleware::class)));
    }

    #[Test]
    public function theStreamingDispatcherReceivesTheSharedCounter(): void
    {
        self::assertSame($this->sharedCounter(), $this->counterOf($this->get(StreamingDispatcher::class)));
    }

    private function sharedCounter(): ProviderRetryCounter
    {
        $counter = $this->get(ProviderRetryCounter::class);
        self::assertInstanceOf(ProviderRetryCounter::class, $counter);

        return $counter;
    }

    /**
     * The `retryCounter` a wired service actually holds. A null there is the
     * failure this test exists for — the registry's argument is nullable, so a
     * wiring that never supplied one compiles and then measures nothing; the
     * assertion below is what turns that into a red test, which is why this
     * returns a non-nullable type.
     */
    private function counterOf(object $service): ProviderRetryCounter
    {
        $property = new ReflectionProperty($service, 'retryCounter');
        $value    = $property->getValue($service);

        self::assertInstanceOf(
            ProviderRetryCounter::class,
            $value,
            sprintf('%s was built without a retry counter; its rows would report a measured 0.', $service::class),
        );

        return $value;
    }
}
