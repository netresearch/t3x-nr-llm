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

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(1, 1));
    }
}
