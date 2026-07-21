<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\FailureClass;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

#[CoversClass(FailureClassifier::class)]
final class FailureClassifierTest extends TestCase
{
    #[Test]
    public function classifiesTheProviderExceptionFamily(): void
    {
        self::assertSame(FailureClass::CIRCUIT_OPEN, FailureClassifier::classify(new CircuitOpenException('openai', 30)));
        self::assertSame(FailureClass::CONNECTION, FailureClassifier::classify(new ProviderConnectionException('down')));
        self::assertSame(FailureClass::CONFIGURATION, FailureClassifier::classify(new ProviderConfigurationException('misconfigured')));
        self::assertSame(FailureClass::AUTH, FailureClassifier::classify(new ProviderAuthenticationException('nope', 401)));
        self::assertSame(FailureClass::RATE_LIMIT, FailureClassifier::classify(new ProviderRateLimitException('slow down', 429)));
    }

    #[Test]
    public function classifiesAResponseExceptionByItsStatusCode(): void
    {
        self::assertSame(FailureClass::AUTH, FailureClassifier::classify(new ProviderResponseException('forbidden', 403)));
        self::assertSame(FailureClass::RATE_LIMIT, FailureClassifier::classify(new ProviderResponseException('throttled', 429)));
        self::assertSame(FailureClass::CLIENT_ERROR, FailureClassifier::classify(new ProviderResponseException('bad request', 400)));
        self::assertSame(FailureClass::SERVER_ERROR, FailureClassifier::classify(new ProviderResponseException('boom', 500)));
        self::assertSame(FailureClass::SERVER_ERROR, FailureClassifier::classify(new ProviderResponseException('gateway', 503)));
    }

    #[Test]
    public function treatsAPsr18NetworkExceptionAsAConnectionFailure(): void
    {
        $networkError = new class ('offline') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new RuntimeException('not needed for the test', 1);
            }
        };

        self::assertSame(FailureClass::CONNECTION, FailureClassifier::classify($networkError));
    }

    #[Test]
    public function anUnrecognisedThrowableIsUnknownAndConservativelyHandled(): void
    {
        $class = FailureClassifier::classify(new RuntimeException('who knows', 1));

        self::assertSame(FailureClass::UNKNOWN, $class);
        self::assertFalse($class->isRetryable());
        self::assertFalse($class->tripsCircuit());
    }

    #[Test]
    public function classifiesAFallbackChainByItsMostRecentAttempt(): void
    {
        // The wrapper alone would classify UNKNOWN (not retryable); the queue
        // retry (ADR-104) must react to the freshest provider condition, so the
        // chain classifies by its LAST attempt's error — here a 503.
        $chain = FallbackChainExhaustedException::fromAttempts([
            ['configuration' => 'primary', 'error' => new ProviderConnectionException('down')],
            ['configuration' => 'fallback', 'error' => new ProviderResponseException('gateway', 503)],
        ]);

        $class = FailureClassifier::classify($chain);

        self::assertSame(FailureClass::SERVER_ERROR, $class);
        self::assertTrue($class->isRetryable());
    }

    #[Test]
    public function anEmptyFallbackChainIsConservativelyUnknown(): void
    {
        $class = FailureClassifier::classify(FallbackChainExhaustedException::fromAttempts([]));

        self::assertSame(FailureClass::UNKNOWN, $class);
        self::assertFalse($class->isRetryable());
    }
}
