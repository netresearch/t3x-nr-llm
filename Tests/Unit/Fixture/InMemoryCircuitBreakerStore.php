<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerStoreInterface;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;

/**
 * In-memory circuit breaker store for unit tests.
 *
 * Holds one state per provider (seed it via {@see self::seed()}) and captures
 * the saved states so assertions verify what the middleware persisted — never a
 * mock return.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitState> */
    private array $states = [];

    /** @var list<array{provider: string, state: CircuitState, lifetime: int}> */
    public array $saves = [];

    public function seed(string $provider, CircuitState $state): void
    {
        $this->states[$provider] = $state;
    }

    public function load(string $provider): CircuitState
    {
        return $this->states[$provider] ?? CircuitState::closed();
    }

    public function save(string $provider, CircuitState $state, int $lifetimeSeconds): void
    {
        $this->states[$provider] = $state;
        $this->saves[]           = [
            'provider' => $provider,
            'state'    => $state,
            'lifetime' => $lifetimeSeconds,
        ];
    }
}
