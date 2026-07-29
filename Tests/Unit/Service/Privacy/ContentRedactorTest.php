<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The shapes this redactor used to miss while the response guardrail already
     * caught them — so a secret masked on the way to a provider was still written
     * to the database in cleartext (ADR-123).
     *
     * @return iterable<string, array{string}>
     */
    public static function previouslyMissedShapeProvider(): iterable
    {
        yield 'OpenAI project key' => ['sk-proj-AbCdEf01234567890abcdefGHIJKL'];
        yield 'GitHub PAT' => ['ghp_' . str_repeat('a', 36)];
        yield 'GitHub fine-grained PAT' => ['github_pat_' . str_repeat('b', 30)];
        yield 'AWS access key' => ['AKIAIOSFODNN7EXAMPLE'];
        yield 'Google API key' => ['AIza' . str_repeat('c', 35)];
        yield 'Slack bot token' => ['xoxb-1234567890-abcdefghij'];
        yield 'bare JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcDEF123'];
    }

    #[Test]
    #[DataProvider('previouslyMissedShapeProvider')]
    public function redactsTheShapesTheGuardrailAlreadyCaught(string $secret): void
    {
        $out = (new ContentRedactor())->redact('the value is ' . $secret . ' — keep it safe');

        self::assertIsString($out);
        self::assertStringNotContainsString($secret, $out);
        self::assertStringStartsWith('the value is ', $out);
    }

    #[Test]
    public function ordinaryContentIsStoredUnchanged(): void
    {
        $content = 'Summarise the following page about our opening hours.';

        self::assertSame($content, (new ContentRedactor())->redact($content));
    }

    #[Test]
    public function nullStaysNull(): void
    {
        self::assertNull((new ContentRedactor())->redact(null));
    }
}
