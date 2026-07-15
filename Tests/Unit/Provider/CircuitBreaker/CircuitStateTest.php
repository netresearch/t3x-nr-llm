<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\CircuitBreaker;

use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitStatus;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CircuitState::class)]
#[CoversClass(CircuitStatus::class)]
final class CircuitStateTest extends AbstractUnitTestCase
{
    #[Test]
    public function closedFactoryHasNoFailuresAndNoOpenTimestamp(): void
    {
        $state = CircuitState::closed();

        self::assertSame(0, $state->consecutiveFailures);
        self::assertNull($state->openedAt);
        self::assertTrue($state->isPristine());
    }

    #[Test]
    public function statusIsClosedWhenNeverOpened(): void
    {
        $state = new CircuitState(3, null);

        self::assertSame(CircuitStatus::Closed, $state->status(1_000, 30));
        // A failure streak without an open timestamp is still "closed" — it is
        // counting toward the threshold, not tripped.
        self::assertFalse($state->isPristine());
    }

    #[Test]
    public function statusIsOpenWithinCooldownAndHalfOpenAfter(): void
    {
        $openedAt = 1_000;
        $state    = new CircuitState(5, $openedAt);

        self::assertSame(CircuitStatus::Open, $state->status($openedAt + 29, 30));
        // Boundary: exactly at cooldown the window has elapsed → half-open.
        self::assertSame(CircuitStatus::HalfOpen, $state->status($openedAt + 30, 30));
        self::assertSame(CircuitStatus::HalfOpen, $state->status($openedAt + 120, 30));
    }

    #[Test]
    public function secondsUntilHalfOpenCountsDownAndFloorsAtZero(): void
    {
        $openedAt = 1_000;
        $state    = new CircuitState(5, $openedAt);

        self::assertSame(30, $state->secondsUntilHalfOpen($openedAt, 30));
        self::assertSame(1, $state->secondsUntilHalfOpen($openedAt + 29, 30));
        self::assertSame(0, $state->secondsUntilHalfOpen($openedAt + 30, 30));
        self::assertSame(0, $state->secondsUntilHalfOpen($openedAt + 999, 30));
        // Not open → no wait.
        self::assertSame(0, (new CircuitState(0, null))->secondsUntilHalfOpen(1_000, 30));
    }

    #[Test]
    public function roundTripsThroughArray(): void
    {
        $state = new CircuitState(4, 12_345);

        self::assertSame(['consecutiveFailures' => 4, 'openedAt' => 12_345], $state->toArray());

        $restored = CircuitState::fromArray($state->toArray());
        self::assertSame(4, $restored->consecutiveFailures);
        self::assertSame(12_345, $restored->openedAt);
    }

    #[Test]
    public function fromArrayDecaysMalformedInputToClosed(): void
    {
        // A corrupt entry must never wedge a provider open.
        $state = CircuitState::fromArray([
            'consecutiveFailures' => 'not-an-int',
            'openedAt'            => -7,
        ]);

        self::assertSame(0, $state->consecutiveFailures);
        self::assertNull($state->openedAt);

        $empty = CircuitState::fromArray([]);
        self::assertTrue($empty->isPristine());
    }
}
