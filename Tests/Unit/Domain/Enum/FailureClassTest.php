<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\FailureClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FailureClassTest extends TestCase
{
    #[Test]
    public function retryableCoversProviderSideFaultsAndAnOpenCircuit(): void
    {
        $retryable = array_values(array_filter(FailureClass::cases(), static fn(FailureClass $c): bool => $c->isRetryable()));

        self::assertEqualsCanonicalizing(
            [FailureClass::CONNECTION, FailureClass::RATE_LIMIT, FailureClass::SERVER_ERROR, FailureClass::CIRCUIT_OPEN],
            $retryable,
        );
    }

    #[Test]
    public function ourSideFaultsAreNotRetryable(): void
    {
        self::assertFalse(FailureClass::AUTH->isRetryable());
        self::assertFalse(FailureClass::CONFIGURATION->isRetryable());
        self::assertFalse(FailureClass::CLIENT_ERROR->isRetryable());
        self::assertFalse(FailureClass::UNKNOWN->isRetryable());
    }

    #[Test]
    public function trippingCoversProviderSideFaultsButNotAnAlreadyOpenCircuit(): void
    {
        $tripping = array_values(array_filter(FailureClass::cases(), static fn(FailureClass $c): bool => $c->tripsCircuit()));

        self::assertEqualsCanonicalizing(
            [FailureClass::CONNECTION, FailureClass::RATE_LIMIT, FailureClass::SERVER_ERROR],
            $tripping,
        );

        // An open circuit must not re-trip: that would count the breaker's own
        // refusal as a fresh provider fault.
        self::assertFalse(FailureClass::CIRCUIT_OPEN->tripsCircuit());
    }
}
