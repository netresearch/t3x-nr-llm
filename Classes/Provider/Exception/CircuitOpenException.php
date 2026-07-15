<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

/**
 * Thrown by {@see \Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware}
 * when a provider's circuit is open: the call fails fast instead of waiting on a
 * timeout against a provider that just failed repeatedly (ADR-063).
 *
 * Extends {@see ProviderException} so it inherits the ADR-053 marker interface.
 * {@see \Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware} treats it as
 * retryable (like a connection failure), so an open circuit routes the request
 * to the next configuration in the fallback chain rather than surfacing as a
 * hard error — the whole point of tripping fast is to free the request to try a
 * healthy provider.
 */
final class CircuitOpenException extends ProviderException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            sprintf(
                'Circuit breaker open for provider "%s"; retry in ~%d second(s)',
                $provider,
                $retryAfterSeconds,
            ),
            1752570001,
        );
    }
}
