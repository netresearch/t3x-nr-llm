<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How an AI provider call failed, in terms the retry and circuit-breaker logic
 * can act on (ADR-095).
 *
 * The retry/fallback middleware, the circuit breaker and the streaming
 * dispatcher each carried their own `instanceof` ladder deciding "is this worth
 * retrying". They had drifted apart — one retried on a 5xx, another did not.
 * This enum is the single vocabulary; {@see \Netresearch\NrLlm\Provider\Middleware\FailureClassifier}
 * maps a throwable onto it, and the two questions below are answered once.
 */
enum FailureClass: string
{
    /**
     * The request never reached the provider (DNS, TCP, TLS, timeout).
     */
    case CONNECTION = 'connection';

    /**
     * HTTP 429 — throttled; a different provider may not be.
     */
    case RATE_LIMIT = 'rateLimit';

    /**
     * HTTP 401/403 — bad or missing credentials. Retrying the same call cannot help.
     */
    case AUTH = 'auth';

    /**
     * The provider is misconfigured (missing endpoint, unusable model).
     */
    case CONFIGURATION = 'configuration';

    /**
     * HTTP 4xx other than auth/429 — the request itself is wrong.
     */
    case CLIENT_ERROR = 'clientError';

    /**
     * HTTP 5xx — the provider failed; another one might not.
     */
    case SERVER_ERROR = 'serverError';

    /**
     * The breaker is open for this provider — it just failed repeatedly.
     */
    case CIRCUIT_OPEN = 'circuitOpen';

    /**
     * Nothing recognisable — treated conservatively (not retried, does not trip).
     */
    case UNKNOWN = 'unknown';

    /**
     * Whether re-running the call — against a fallback provider — could
     * plausibly succeed. A throttled, unreachable or 5xx-ing provider may have a
     * healthy sibling; an open circuit is exactly the signal to route onward.
     * A bad credential, a malformed request or a misconfiguration will fail the
     * same way everywhere.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::CONNECTION, self::RATE_LIMIT, self::SERVER_ERROR, self::CIRCUIT_OPEN => true,
            self::AUTH, self::CONFIGURATION, self::CLIENT_ERROR, self::UNKNOWN => false,
        };
    }

    /**
     * Whether this failure should count towards opening the circuit for the
     * provider. A provider-side fault — unreachable, throttled, 5xx — is
     * evidence the provider is unhealthy. An already-open circuit must NOT
     * re-trip (that would be counting the breaker's own refusal as a new
     * fault), and a client/auth/config error is our fault, not the provider's.
     */
    public function tripsCircuit(): bool
    {
        return match ($this) {
            self::CONNECTION, self::RATE_LIMIT, self::SERVER_ERROR => true,
            self::AUTH, self::CONFIGURATION, self::CLIENT_ERROR, self::CIRCUIT_OPEN, self::UNKNOWN => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
