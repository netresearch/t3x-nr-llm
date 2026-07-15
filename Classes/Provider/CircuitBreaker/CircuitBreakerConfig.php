<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

/**
 * Resolved circuit breaker tunables (ADR-063).
 *
 * A typed snapshot of the `circuitBreaker.*` extension settings, read once per
 * pipeline run by {@see \Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware}
 * so the rest of the middleware works with named properties rather than
 * repeated string keys.
 */
final readonly class CircuitBreakerConfig
{
    public function __construct(
        public bool $enabled,
        public int $failureThreshold,
        public int $cooldownSeconds,
    ) {}
}
