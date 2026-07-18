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
use Netresearch\NrLlm\Service\Guardrail\ProviderContentFilterGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderContentFilterGuardrail::class)]
final class ProviderContentFilterGuardrailTest extends TestCase
{
    #[Test]
    public function deniesAContentFilteredResponse(): void
    {
        $response = new CompletionResponse('', 'test-model', UsageStatistics::fromTokens(0, 0), 'content_filter');

        $result = (new ProviderContentFilterGuardrail())->checkOutput($response);

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertNotSame('', $result->reason);
    }

    #[Test]
    public function allowsANormalResponse(): void
    {
        $response = new CompletionResponse('all good', 'test-model', UsageStatistics::fromTokens(1, 1), 'stop');

        $result = (new ProviderContentFilterGuardrail())->checkOutput($response);

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
    }
}
