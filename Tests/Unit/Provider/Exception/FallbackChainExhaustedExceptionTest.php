<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Exception;

use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(FallbackChainExhaustedException::class)]
class FallbackChainExhaustedExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function isProviderException(): void
    {
        $exception = FallbackChainExhaustedException::fromAttempts([]);

        self::assertInstanceOf(ProviderException::class, $exception);
    }

    #[Test]
    public function fromAttemptsListsAllConfigurationsInMessage(): void
    {
        $attempts = [
            ['configuration' => 'primary', 'error' => new ProviderConnectionException('boom', 1)],
            ['configuration' => 'fallback-a', 'error' => new ProviderConnectionException('boom', 2)],
            ['configuration' => 'fallback-b', 'error' => new ProviderConnectionException('boom', 3)],
        ];

        $exception = FallbackChainExhaustedException::fromAttempts($attempts);

        self::assertStringContainsString('primary -> fallback-a -> fallback-b', $exception->getMessage());
        self::assertStringContainsString('3 fallback configuration(s) failed', $exception->getMessage());
    }

    #[Test]
    public function fromAttemptsSetsLastAttemptAsPrevious(): void
    {
        $last = new ProviderConnectionException('last-error', 99);
        $attempts = [
            ['configuration' => 'a', 'error' => new ProviderConnectionException('first', 1)],
            ['configuration' => 'b', 'error' => $last],
        ];

        $exception = FallbackChainExhaustedException::fromAttempts($attempts);

        self::assertSame($last, $exception->getPrevious());
    }

    #[Test]
    public function fromAttemptsHandlesEmptyAttempts(): void
    {
        $exception = FallbackChainExhaustedException::fromAttempts([]);

        self::assertStringContainsString('0 fallback configuration(s) failed', $exception->getMessage());
        self::assertNull($exception->getPrevious());
        self::assertSame([], $exception->getAttemptErrors());
        self::assertSame([], $exception->getAttemptedConfigurations());
    }

    #[Test]
    public function getAttemptErrorsReturnsAllAttempts(): void
    {
        $err1 = new ProviderConnectionException('one', 1);
        $err2 = new ProviderConnectionException('two', 2);
        $attempts = [
            ['configuration' => 'x', 'error' => $err1],
            ['configuration' => 'y', 'error' => $err2],
        ];

        $exception = FallbackChainExhaustedException::fromAttempts($attempts);

        self::assertSame($attempts, $exception->getAttemptErrors());
    }

    #[Test]
    public function getAttemptedConfigurationsReturnsIdentifiersInOrder(): void
    {
        $attempts = [
            ['configuration' => 'alpha', 'error' => new ProviderConnectionException('a', 1)],
            ['configuration' => 'beta', 'error' => new ProviderConnectionException('b', 2)],
            ['configuration' => 'gamma', 'error' => new ProviderConnectionException('c', 3)],
        ];

        $exception = FallbackChainExhaustedException::fromAttempts($attempts);

        self::assertSame(['alpha', 'beta', 'gamma'], $exception->getAttemptedConfigurations());
    }

    #[Test]
    public function constructorAcceptsPreviousExplicitly(): void
    {
        $previous = new ProviderConnectionException('root cause', 42);

        $exception = new FallbackChainExhaustedException(
            'custom',
            99,
            [['configuration' => 'c', 'error' => $previous]],
            $previous,
        );

        self::assertSame('custom', $exception->getMessage());
        self::assertSame(99, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
