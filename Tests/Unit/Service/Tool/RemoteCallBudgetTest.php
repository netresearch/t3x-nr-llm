<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\RemoteCallBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteCallBudget::class)]
final class RemoteCallBudgetTest extends TestCase
{
    #[Test]
    public function grantsExactlyItsLimit(): void
    {
        $budget = new RemoteCallBudget(3);

        self::assertTrue($budget->tryConsume());
        self::assertTrue($budget->tryConsume());
        self::assertTrue($budget->tryConsume());
        self::assertFalse($budget->tryConsume());
        self::assertSame(3, $budget->spent());
    }

    /**
     * A refused claim must not raise the count, or a long enough run would
     * report having spent more than the limit it was never given.
     */
    #[Test]
    public function aRefusedClaimCostsNothing(): void
    {
        $budget = new RemoteCallBudget(1);
        $budget->tryConsume();

        $budget->tryConsume();
        $budget->tryConsume();

        self::assertSame(1, $budget->spent());
    }

    #[Test]
    public function aZeroLimitGrantsNothing(): void
    {
        self::assertFalse((new RemoteCallBudget(0))->tryConsume());
    }

    #[Test]
    public function defaultsToTheDeclaredLimit(): void
    {
        self::assertSame(RemoteCallBudget::DEFAULT_LIMIT, (new RemoteCallBudget())->limit());
    }
}
