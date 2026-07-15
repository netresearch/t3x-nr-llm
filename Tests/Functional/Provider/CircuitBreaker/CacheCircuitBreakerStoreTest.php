<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\CircuitBreaker;

use Netresearch\NrLlm\Provider\CircuitBreaker\CacheCircuitBreakerStore;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;

/**
 * Round-trips circuit state through the real `nrllm_circuit` cache, proving the
 * VariableFrontend + entry-identifier sanitisation work end to end.
 */
#[CoversClass(CacheCircuitBreakerStore::class)]
#[CoversClass(CircuitState::class)]
final class CacheCircuitBreakerStoreTest extends AbstractFunctionalTestCase
{
    private CacheCircuitBreakerStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        // The store is private in the container by design; instantiate it with
        // the real core cache manager (the nrllm_circuit cache is registered by
        // ext_localconf.php, which the functional bootstrap loads).
        $cacheManager = $this->get(Typo3CacheManager::class);
        self::assertInstanceOf(Typo3CacheManager::class, $cacheManager);

        $this->store = new CacheCircuitBreakerStore($cacheManager, new NullLogger());
    }

    #[Test]
    public function loadReturnsClosedForUnknownProvider(): void
    {
        $state = $this->store->load('never-seen');

        self::assertTrue($state->isPristine());
    }

    #[Test]
    public function savedStateRoundTrips(): void
    {
        $this->store->save('openai', new CircuitState(4, 1_700_000_000), 300);

        $loaded = $this->store->load('openai');
        self::assertSame(4, $loaded->consecutiveFailures);
        self::assertSame(1_700_000_000, $loaded->openedAt);
    }

    #[Test]
    public function providerIdentifiersWithReservedCharactersDoNotCollide(): void
    {
        // The ad-hoc scheme carries colons the cache frontend rejects; the store
        // must map them to distinct, valid entry identifiers.
        $this->store->save('ad-hoc:chat:openai', new CircuitState(1, null), 300);
        $this->store->save('ad-hoc:chat:groq', new CircuitState(7, 1_700_000_500), 300);

        self::assertSame(1, $this->store->load('ad-hoc:chat:openai')->consecutiveFailures);
        self::assertSame(7, $this->store->load('ad-hoc:chat:groq')->consecutiveFailures);
        self::assertSame(1_700_000_500, $this->store->load('ad-hoc:chat:groq')->openedAt);
    }

    #[Test]
    public function laterSaveOverwritesEarlierState(): void
    {
        $this->store->save('openai', new CircuitState(5, 1_700_000_000), 300);
        $this->store->save('openai', CircuitState::closed(), 300);

        self::assertTrue($this->store->load('openai')->isPristine());
    }
}
