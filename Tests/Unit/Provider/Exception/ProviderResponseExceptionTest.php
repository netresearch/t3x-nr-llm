<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Exception;

use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProviderResponseException::class)]
final class ProviderResponseExceptionTest extends TestCase
{
    #[Test]
    public function exposesTypedProperties(): void
    {
        $exception = new ProviderResponseException(
            message: 'Bad request: invalid prompt',
            httpStatus: 400,
            responseBody: '{"error":{"code":"invalid_prompt","message":"Bad"}}',
            endpoint: 'chat/completions',
        );

        self::assertSame('Bad request: invalid prompt', $exception->getMessage());
        self::assertSame(400, $exception->httpStatus);
        self::assertSame('{"error":{"code":"invalid_prompt","message":"Bad"}}', $exception->responseBody);
        self::assertSame('chat/completions', $exception->endpoint);
    }

    #[Test]
    public function legacyTwoArgConstructorStillWorks(): void
    {
        // Back-compat path — pre-REC-#8 callers passed only
        // `(message, statusCode)`. The status code lands as both the
        // exception code and the new typed `httpStatus`; the new typed
        // fields default to empty strings.
        $exception = new ProviderResponseException('OpenRouter API error (500): boom', 500);

        self::assertSame(500, $exception->httpStatus);
        self::assertSame(500, $exception->getCode());
        self::assertSame('', $exception->responseBody);
        self::assertSame('', $exception->endpoint);
    }

    #[Test]
    public function legacyThreeArgPositionalConstructorStillWorks(): void
    {
        // The riskiest back-compat path: callers that wrote
        // `new ProviderResponseException($msg, $status, $previous)`
        // positionally. The constructor parameter order is preserved
        // so $previous still lands as the previous exception (not
        // silently coerced into responseBody, which would have
        // raised a TypeError).
        $cause = new RuntimeException('low-level network reset');
        $exception = new ProviderResponseException('Bad gateway', 502, $cause);

        self::assertSame(502, $exception->httpStatus);
        self::assertSame($cause, $exception->getPrevious());
        self::assertSame('', $exception->responseBody);
        self::assertSame('', $exception->endpoint);
    }

    #[Test]
    public function previousChainPropagatesViaNamedArg(): void
    {
        $cause = new RuntimeException('low-level network reset');
        $exception = new ProviderResponseException(
            message: 'Bad gateway',
            httpStatus: 502,
            previous: $cause,
        );

        self::assertSame($cause, $exception->getPrevious());
    }

    #[Test]
    public function endpointStripsQueryStringToPreventSecretLeak(): void
    {
        // Gemini and similar providers ship the API key on the URL
        // (`?key=<secret>`). The exception field is meant for diagnostics
        // only and must never leak credentials to log sinks / telemetry.
        $exception = new ProviderResponseException(
            message: 'Quota exceeded',
            httpStatus: 429,
            endpoint: 'v1beta/models/gemini-pro:generateContent?key=AIzaSecret123&alt=json',
        );

        self::assertSame('v1beta/models/gemini-pro:generateContent', $exception->endpoint);
        self::assertStringNotContainsString('AIzaSecret123', $exception->endpoint);
        self::assertStringNotContainsString('?', $exception->endpoint);
    }
}
