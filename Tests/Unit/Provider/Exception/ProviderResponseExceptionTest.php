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
    public function defaultsCleanlyForCallersThatOnlySupplyMessage(): void
    {
        // Back-compat path — the pre-REC-#8 callers passed only message
        // and an integer status as `code`. The latter still works
        // (httpStatus picks up the same value) but the new defaults
        // for the other typed fields keep the API forgiving.
        $exception = new ProviderResponseException('OpenRouter API error (500): boom', 500);

        self::assertSame(500, $exception->httpStatus);
        self::assertSame(500, $exception->getCode());
        self::assertSame('', $exception->responseBody);
        self::assertSame('', $exception->endpoint);
    }

    #[Test]
    public function previousChainPropagates(): void
    {
        $cause = new RuntimeException('low-level network reset');
        $exception = new ProviderResponseException(
            message: 'Bad gateway',
            httpStatus: 502,
            previous: $cause,
        );

        self::assertSame($cause, $exception->getPrevious());
    }
}
