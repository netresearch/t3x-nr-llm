<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

/**
 * Persistence boundary for per-provider circuit state (ADR-063).
 *
 * Deliberately narrow: load the state for one provider, or save it. The default
 * implementation is cache-backed ({@see CacheCircuitBreakerStore}) so the state
 * is transient, shared across web workers via the instance's cache backend
 * (Redis/Valkey/…), and self-decaying — a circuit the store forgets reads as
 * closed, which is the fail-safe default.
 */
interface CircuitBreakerStoreInterface
{
    /**
     * Load the circuit state for a provider. A never-seen or expired provider
     * returns a fresh closed state.
     */
    public function load(string $provider): CircuitState;

    /**
     * Persist the circuit state for a provider with the given lifetime in
     * seconds. Must never throw for a caller-visible reason: a store failure
     * must not break the provider call the circuit guards.
     */
    public function save(string $provider, CircuitState $state, int $lifetimeSeconds): void;
}
