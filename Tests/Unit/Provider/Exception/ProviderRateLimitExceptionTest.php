<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Exception;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Excluded from the coverage source set in `Build/phpunit.xml`; behaviour is
 * still asserted here (see ProviderResponseExceptionTest for the rationale).
 */
#[CoversNothing]
final class ProviderRateLimitExceptionTest extends TestCase
{
    #[Test]
    public function isCaughtAsProviderResponseExceptionForBackwardCompatibility(): void
    {
        $exception = new ProviderRateLimitException(message: 'Rate limit exceeded', httpStatus: 429);

        self::assertInstanceOf(ProviderResponseException::class, $exception);
        self::assertInstanceOf(ProviderException::class, $exception);
        self::assertInstanceOf(NrLlmExceptionInterface::class, $exception);
    }

    #[Test]
    public function preservesCode429SoRetryAndCircuitBreakerMiddlewareStillFire(): void
    {
        // ADR-080 BC anchor: FallbackMiddleware and CircuitBreakerMiddleware
        // key off getCode() === 429. The typed class must keep that code.
        $exception = new ProviderRateLimitException(
            message: 'Rate limit exceeded',
            httpStatus: 429,
            responseBody: '{"error":{"message":"Too Many Requests"}}',
            endpoint: 'chat/completions',
        );

        self::assertSame(429, $exception->getCode());
        self::assertSame(429, $exception->httpStatus);
        self::assertSame('{"error":{"message":"Too Many Requests"}}', $exception->responseBody);
    }
}
