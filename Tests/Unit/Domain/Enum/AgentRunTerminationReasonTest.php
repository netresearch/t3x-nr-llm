<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentRunTerminationReasonTest extends TestCase
{
    #[Test]
    public function onlyProviderFailureIsRetryable(): void
    {
        // A context-truncated run (ADR-107) re-sends the same oversized floor and
        // overflows again — it must NOT be retryable.
        self::assertFalse(AgentRunTerminationReason::CONTEXT_TRUNCATED->isRetryable());
        self::assertTrue(AgentRunTerminationReason::PROVIDER_FAILED->isRetryable());
        self::assertFalse(AgentRunTerminationReason::MAX_ITERATIONS->isRetryable());
    }

    #[Test]
    public function contextTruncatedRoundTripsThroughItsBackingValue(): void
    {
        self::assertSame('context_truncated', AgentRunTerminationReason::CONTEXT_TRUNCATED->value);
        self::assertSame(AgentRunTerminationReason::CONTEXT_TRUNCATED, AgentRunTerminationReason::tryFromString('context_truncated'));
    }
}
