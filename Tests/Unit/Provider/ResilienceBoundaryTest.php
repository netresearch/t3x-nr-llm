<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Enum\FailureClass;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitStatus;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(CircuitState::class)]
#[CoversClass(FailureClassifier::class)]
final class ResilienceBoundaryTest extends TestCase
{
    #[Test]
    public function aDefaultStateIsClosedWithoutAPersistableFailureStreak(): void
    {
        $state = new CircuitState();

        self::assertSame(0, $state->consecutiveFailures);
        self::assertNull($state->openedAt);
        self::assertTrue($state->isPristine());
        self::assertSame(CircuitStatus::Closed, $state->status(1, 30));
        self::assertSame(
            ['consecutiveFailures' => 0, 'openedAt' => null],
            $state->toArray(),
        );
    }

    #[Test]
    public function aNeverOpenedCircuitNeedsNoWaitEvenAtTheBeginningOfTheClock(): void
    {
        $state = new CircuitState(2);

        self::assertSame(0, $state->secondsUntilHalfOpen(0, 30));
        self::assertSame(CircuitStatus::Closed, $state->status(0, 30));
        self::assertSame(2, $state->consecutiveFailures);
    }

    #[Test]
    #[DataProvider('invalidOpenTimestamps')]
    public function aCorruptOpenTimestampCannotOpenACachedFailureStreak(
        mixed $openedAt,
    ): void {
        $state = CircuitState::fromArray(
            ['consecutiveFailures' => 2, 'openedAt' => $openedAt],
        );

        self::assertNull($state->openedAt);
        self::assertSame(2, $state->consecutiveFailures);
        self::assertFalse($state->isPristine());
        self::assertSame(CircuitStatus::Closed, $state->status(0, 30));
        self::assertSame(0, $state->secondsUntilHalfOpen(0, 30));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOpenTimestamps(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'numeric string' => ['1'];
        yield 'float' => [1.0];
        yield 'boolean' => [true];
        yield 'missing timestamp' => [null];
    }

    #[Test]
    public function theFirstPositiveCachedTimestampRetainsItsCooldown(): void
    {
        $state = CircuitState::fromArray(['consecutiveFailures' => 2, 'openedAt' => 1]);

        self::assertSame(1, $state->openedAt);
        self::assertSame(CircuitStatus::Open, $state->status(1, 30));
        self::assertSame(30, $state->secondsUntilHalfOpen(1, 30));
        self::assertSame(CircuitStatus::HalfOpen, $state->status(31, 30));
    }

    #[Test]
    #[DataProvider('statusBoundaries')]
    public function responseStatusBoundariesRetainTheirFailureAndRetryMeaning(
        int $status,
        FailureClass $expected,
        bool $retryable,
        bool $tripping,
    ): void {
        $caught = null;
        $actual = null;
        try {
            $actual = FailureClassifier::classify(
                new ProviderResponseException('Status boundary', $status),
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'Unrecognised response codes must classify conservatively without throwing.',
        );
        self::assertSame($expected, $actual);
        self::assertNotNull($actual);
        self::assertSame($retryable, $actual->isRetryable());
        self::assertSame($tripping, $actual->tripsCircuit());
    }

    /**
     * @return iterable<string, array{int, FailureClass, bool, bool}>
     */
    public static function statusBoundaries(): iterable
    {
        yield 'no status' => [0, FailureClass::UNKNOWN, false, false];
        yield 'negative status' => [-1, FailureClass::UNKNOWN, false, false];
        yield 'below client errors' => [399, FailureClass::UNKNOWN, false, false];
        yield 'first client error' => [400, FailureClass::CLIENT_ERROR, false, false];
        yield 'last client error' => [499, FailureClass::CLIENT_ERROR, false, false];
        yield 'first server error' => [500, FailureClass::SERVER_ERROR, true, true];
        yield 'last server error' => [599, FailureClass::SERVER_ERROR, true, true];
        yield 'above server errors' => [600, FailureClass::UNKNOWN, false, false];
    }
}
