<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentRedactor::class)]
final class ContentRedactorTest extends TestCase
{
    #[Test]
    public function returnsNullForNull(): void
    {
        self::assertNull((new ContentRedactor())->redact(null));
    }

    #[Test]
    public function stripsCredentialQueryParameters(): void
    {
        $out = (new ContentRedactor())->redact('GET https://api.example.com/v1/models?token=abc123secret&x=1');

        self::assertIsString($out);
        self::assertStringContainsString('token=***', $out);
        self::assertStringNotContainsString('abc123secret', $out);
    }

    #[Test]
    public function redactsEmailAddresses(): void
    {
        $out = (new ContentRedactor())->redact('contact john.doe@example.com for access');

        self::assertIsString($out);
        self::assertStringNotContainsString('john.doe@example.com', $out);
        self::assertStringContainsString('***', $out);
    }

    #[Test]
    public function redactsBearerTokens(): void
    {
        $out = (new ContentRedactor())->redact('Authorization: Bearer sk-abcDEF1234567890abcDEF12');

        self::assertIsString($out);
        self::assertStringNotContainsString('sk-abcDEF1234567890abcDEF12', $out);
        self::assertStringContainsString('***', $out);
    }

    #[Test]
    public function truncatesOverLongContent(): void
    {
        $long = str_repeat('a', 5000);

        $out = (new ContentRedactor())->redact($long);

        self::assertIsString($out);
        self::assertLessThan(mb_strlen($long), mb_strlen($out));
        self::assertStringEndsWith('[truncated]', $out);
    }
}
