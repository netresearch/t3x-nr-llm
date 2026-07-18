<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionInputGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretRedactionInputGuardrail::class)]
final class SecretRedactionInputGuardrailTest extends TestCase
{
    #[Test]
    public function redactsAnApiKeyFromTheOutgoingPrompt(): void
    {
        $result = (new SecretRedactionInputGuardrail())->checkInput('please use sk-abcdef0123456789ABCDEF for this');

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringContainsString('sk-***', $result->redactedContent);
        self::assertStringNotContainsString('sk-abcdef0123456789ABCDEF', $result->redactedContent);
        self::assertStringContainsString('prompt', $result->reason);
    }

    #[Test]
    public function redactsAUrlCredentialAndABearerTokenFromThePrompt(): void
    {
        $result = (new SecretRedactionInputGuardrail())->checkInput(
            'call https://api.example.com/x?api_key=SUPERSECRET1 with Bearer abc123def456ghi789',
        );

        self::assertSame(GuardrailVerdict::REDACT, $result->verdict);
        self::assertNotNull($result->redactedContent);
        self::assertStringNotContainsString('SUPERSECRET1', $result->redactedContent);
        self::assertStringContainsString('Bearer ***', $result->redactedContent);
    }

    #[Test]
    public function allowsACleanPrompt(): void
    {
        $result = (new SecretRedactionInputGuardrail())->checkInput('just a normal question about the weather');

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertNull($result->redactedContent);
    }
}
