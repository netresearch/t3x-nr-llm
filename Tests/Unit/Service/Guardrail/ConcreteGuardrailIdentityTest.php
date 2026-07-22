<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Service\Guardrail\ProviderContentFilterGuardrail;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionInputGuardrail;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the identity + policy classification of the shipped guardrails (ADR-106):
 * both secret-redaction sides share one mandatory identifier (so a config can
 * never disable it on either axis), and the provider content filter is optional.
 */
#[CoversNothing]
final class ConcreteGuardrailIdentityTest extends TestCase
{
    #[Test]
    public function secretRedactionIsMandatoryAndSharesOneIdentifierAcrossSides(): void
    {
        $output = new SecretRedactionGuardrail();
        $input  = new SecretRedactionInputGuardrail();

        self::assertSame('secret-redaction', $output->getIdentifier());
        self::assertSame('secret-redaction', $input->getIdentifier());
        self::assertTrue($output->isMandatory());
        self::assertTrue($input->isMandatory());
    }

    #[Test]
    public function providerContentFilterIsOptional(): void
    {
        $guardrail = new ProviderContentFilterGuardrail();

        self::assertSame('provider-content-filter', $guardrail->getIdentifier());
        self::assertFalse($guardrail->isMandatory());
    }
}
