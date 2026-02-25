<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ServiceUnavailableException::class)]
class ServiceUnavailableExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function notConfiguredCreatesCorrectException(): void
    {
        $exception = ServiceUnavailableException::notConfigured('translation', 'deepl');

        self::assertStringContainsString('Deepl service is not configured', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('not_configured', $exception->context['reason']);
        self::assertEquals('deepl', $exception->context['provider']);
    }

    #[Test]
    public function notConfiguredCapitalizesProviderName(): void
    {
        $exception = ServiceUnavailableException::notConfigured('speech', 'whisper');

        self::assertStringStartsWith('Whisper', $exception->getMessage());
    }

    #[Test]
    public function serviceDownCreatesCorrectExceptionWithoutHttpStatus(): void
    {
        $exception = ServiceUnavailableException::serviceDown('image', 'dalle');

        self::assertStringContainsString('Dalle service is currently unavailable', $exception->getMessage());
        self::assertStringNotContainsString('HTTP', $exception->getMessage());
        self::assertEquals('image', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('service_down', $exception->context['reason']);
        self::assertEquals('dalle', $exception->context['provider']);
        self::assertNull($exception->context['http_status']);
    }

    #[Test]
    public function serviceDownIncludesHttpStatusWhenProvided(): void
    {
        $exception = ServiceUnavailableException::serviceDown('translation', 'deepl', 503);

        self::assertStringContainsString('HTTP 503', $exception->getMessage());
        self::assertNotNull($exception->context);
        self::assertEquals(503, $exception->context['http_status']);
    }

    #[Test]
    public function serviceDownHandlesDifferentHttpStatuses(): void
    {
        $exception500 = ServiceUnavailableException::serviceDown('api', 'provider', 500);
        $exception429 = ServiceUnavailableException::serviceDown('api', 'provider', 429);

        self::assertStringContainsString('HTTP 500', $exception500->getMessage());
        self::assertStringContainsString('HTTP 429', $exception429->getMessage());
    }

    #[Test]
    public function translatorNotFoundCreatesCorrectException(): void
    {
        $exception = ServiceUnavailableException::translatorNotFound('my-translator');

        self::assertStringContainsString('Translator "my-translator" not found', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('not_found', $exception->context['reason']);
        self::assertEquals('my-translator', $exception->context['identifier']);
    }
}
