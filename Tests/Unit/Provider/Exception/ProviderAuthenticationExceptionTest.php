<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Exception;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Excluded from the coverage source set in `Build/phpunit.xml`; behaviour is
 * still asserted here (see ProviderResponseExceptionTest for the rationale).
 */
#[CoversNothing]
final class ProviderAuthenticationExceptionTest extends TestCase
{
    #[Test]
    public function isCaughtAsProviderResponseExceptionForBackwardCompatibility(): void
    {
        $exception = new ProviderAuthenticationException(message: 'Invalid API key', httpStatus: 401);

        // ADR-080: the typed subclass must remain catchable via every existing arm.
        self::assertInstanceOf(ProviderResponseException::class, $exception);
        self::assertInstanceOf(ProviderException::class, $exception);
        self::assertInstanceOf(NrLlmExceptionInterface::class, $exception);
    }

    #[Test]
    public function inheritsTypedFieldsAndStatusAsCode(): void
    {
        $exception = new ProviderAuthenticationException(
            message: 'Invalid API key',
            httpStatus: 401,
            responseBody: '{"error":{"message":"Unauthorized"}}',
            endpoint: 'chat/completions',
        );

        self::assertSame(401, $exception->httpStatus);
        self::assertSame(401, $exception->getCode());
        self::assertSame('{"error":{"message":"Unauthorized"}}', $exception->responseBody);
        self::assertSame('chat/completions', $exception->endpoint);
    }
}
