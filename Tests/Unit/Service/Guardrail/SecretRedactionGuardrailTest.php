<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretRedactionGuardrail::class)]
final class SecretRedactionGuardrailTest extends TestCase
{
    #[Test]
    public function allowsAResponseWithNoSecrets(): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput($this->response('Here is a perfectly normal answer.'));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertNull($result->redactedContent);
    }

    #[Test]
    public function redactsAnApiKey(): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput($this->response('the key is sk-abcdef0123456789ABCDEF here'));

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringContainsString('sk-***', $result->redactedContent);
        self::assertStringNotContainsString('sk-abcdef0123456789ABCDEF', $result->redactedContent);
    }

    #[Test]
    public function redactsAModernProjectApiKeyWithHyphensAndUnderscores(): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput($this->response('the key is sk-proj-Abc123_def-456GHIjkl789 here'));

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringContainsString('sk-***', $result->redactedContent);
        self::assertStringNotContainsString('sk-proj-Abc123', $result->redactedContent);
    }

    #[Test]
    public function redactsAUrlCredentialAndABearerToken(): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput(
            $this->response('fetch https://api.example.com/x?api_key=SUPERSECRET1 using Bearer abc123def456ghi789'),
        );

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringNotContainsString('SUPERSECRET1', $result->redactedContent);
        self::assertStringContainsString('Bearer ***', $result->redactedContent);
    }

    #[Test]
    public function redactsASecretEchoedIntoTheReasoningBlockLeavingCleanContent(): void
    {
        $response = new CompletionResponse(
            'a perfectly clean answer',
            'test-model',
            UsageStatistics::fromTokens(1, 1),
            'stop',
            '',
            null,
            null,
            'reasoning: the key is sk-abcdef0123456789ABCDEF, use it',
        );

        $result = (new SecretRedactionGuardrail())->checkOutput($response);

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedThinking);
        self::assertStringContainsString('sk-***', $result->redactedThinking);
        self::assertStringNotContainsString('sk-abcdef0123456789ABCDEF', $result->redactedThinking);
        // Clean content passes through unchanged.
        self::assertSame('a perfectly clean answer', $result->redactedContent);
    }

    #[Test]
    public function leavesAResponseWithNoSecretsInEitherContentOrThinkingAlone(): void
    {
        $response = new CompletionResponse(
            'clean answer',
            'test-model',
            UsageStatistics::fromTokens(1, 1),
            'stop',
            '',
            null,
            null,
            'clean reasoning',
        );

        self::assertSame(GuardrailVerdict::ALLOW, (new SecretRedactionGuardrail())->checkOutput($response)->verdict);
    }

    #[Test]
    public function redactsConnectionStringPasswords(): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput($this->response(
            'DATABASE_URL=postgres://appuser:S3cr3tP4ss@db.internal:5432/prod and redis://:MyRedisPw123@10.0.0.5:6379/0',
        ));

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringNotContainsString('S3cr3tP4ss', $result->redactedContent);
        self::assertStringNotContainsString('MyRedisPw123', $result->redactedContent);
        // Scheme, user and host are preserved; only the password is masked.
        self::assertStringContainsString('postgres://appuser:***@db.internal', $result->redactedContent);
        self::assertStringContainsString('redis://:***@10.0.0.5', $result->redactedContent);
    }

    #[Test]
    public function redactsAJsonWebTokenWithoutABearerPrefix(): void
    {
        $jwt = 'eyJ' . str_repeat('a', 12) . '.' . str_repeat('b', 20) . '.' . str_repeat('c', 30);

        $result = (new SecretRedactionGuardrail())->checkOutput($this->response('session token ' . $jwt . ' expires soon'));

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringNotContainsString($jwt, $result->redactedContent);
    }

    #[Test]
    #[DataProvider('vendorTokenProvider')]
    public function redactsHighSignalVendorTokens(string $secret): void
    {
        $result = (new SecretRedactionGuardrail())->checkOutput($this->response('here is ' . $secret . ' ok'));

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringNotContainsString($secret, $result->redactedContent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function vendorTokenProvider(): iterable
    {
        yield 'github classic token'   => ['ghp_' . str_repeat('A', 36)];
        yield 'github fine-grained pat' => ['github_pat_' . str_repeat('B', 30)];
        yield 'aws access key id'      => ['AKIA' . str_repeat('C', 16)];
        yield 'google api key'         => ['AIza' . str_repeat('D', 35)];
        yield 'slack bot token'        => ['xoxb-' . str_repeat('1', 20)];
    }

    #[Test]
    public function masksTheEntireBearerTokenIncludingBase64PaddingChars(): void
    {
        // A base64-standard token (containing + / =) must be masked whole — the
        // pre-fix character class stopped at the first such char, leaking the tail.
        $result = (new SecretRedactionGuardrail())->checkOutput(
            $this->response('Authorization: Bearer AAAABBBBCCCCDDDD+EEEE/FFFF=='),
        );

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringContainsString('Bearer ***', $result->redactedContent);
        self::assertStringNotContainsString('EEEE', $result->redactedContent);
        self::assertStringNotContainsString('FFFF', $result->redactedContent);
    }

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(1, 1));
    }
}
