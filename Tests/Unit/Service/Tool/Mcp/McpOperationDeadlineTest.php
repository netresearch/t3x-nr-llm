<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(McpOperationDeadline::class)]
final class McpOperationDeadlineTest extends AbstractUnitTestCase
{
    #[Test]
    public function theFirstLegIsGrantedTheWholeBudget(): void
    {
        $deadline = McpOperationDeadline::start(new FakeMcpClock(), 20);

        self::assertSame(20, $deadline->totalSeconds());
        self::assertSame(20, $deadline->legTimeoutSeconds());
        self::assertFalse($deadline->isExhausted());
    }

    /**
     * The whole point: what an earlier leg spent is gone. A budget that answered
     * the same number twice would be a per-request timeout wearing a new name.
     */
    #[Test]
    public function everyLegIsGrantedOnlyWhatTheEarlierOnesLeft(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 20);

        $clock->advanceSeconds(4.0);
        self::assertSame(16, $deadline->legTimeoutSeconds());

        $clock->advanceSeconds(2.0);
        self::assertSame(14, $deadline->legTimeoutSeconds());
    }

    #[Test]
    public function theBudgetIsSpentOnceTheClockHasRunPastIt(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 20);

        $clock->advanceSeconds(19.9);
        self::assertFalse($deadline->isExhausted(), 'a tenth of a second is still a budget');

        $clock->advanceSeconds(0.1);
        self::assertTrue($deadline->isExhausted(), 'spending exactly the budget leaves nothing');

        $clock->advanceSeconds(5.0);
        self::assertTrue($deadline->isExhausted());
        self::assertLessThan(0.0, $deadline->remainingSeconds());
    }

    /**
     * The floor, and why it is not cosmetic.
     *
     * The number goes to `VaultHttpClientInterface::withTimeout()`, which treats
     * anything non-positive as "no override" and falls back to
     * `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout']` — TYPO3's default for
     * which is `0`, and Guzzle reads `0` as *wait forever*. A leg granted zero
     * would be the one leg with no bound at all.
     */
    #[Test]
    public function aLegIsNeverGrantedZeroSecondsHoweverLittleIsLeft(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 20);

        $clock->advanceSeconds(19.999);

        self::assertFalse($deadline->isExhausted());
        self::assertSame(
            McpOperationDeadline::MINIMUM_LEG_SECONDS,
            $deadline->legTimeoutSeconds(),
            'a millisecond of budget is rounded up to the floor, never down to no bound',
        );
        self::assertGreaterThan(0, $deadline->legTimeoutSeconds());
    }

    /**
     * Rounding up rather than down is the same guard one step earlier: 0.4
     * seconds truncates to 0, and 0 is unbounded.
     */
    #[Test]
    public function aPartSecondRemainderIsRoundedUpNotTruncated(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 10);

        $clock->advanceSeconds(7.4);

        self::assertSame(3, $deadline->legTimeoutSeconds());
    }

    /**
     * A budget below the floor cannot be honoured: its first leg would already
     * be refused. That is a misconfiguration, and the operation still has to
     * run.
     */
    #[Test]
    public function aBudgetBelowTheFloorIsRaisedToIt(): void
    {
        $deadline = McpOperationDeadline::start(new FakeMcpClock(), 0);

        self::assertSame(McpOperationDeadline::MINIMUM_LEG_SECONDS, $deadline->totalSeconds());
        self::assertFalse($deadline->isExhausted());
        self::assertGreaterThan(0, $deadline->legTimeoutSeconds());
    }

    /**
     * The clock is a monotonic source, so a reading is only meaningful against
     * another reading. A deadline that read one as an absolute duration would
     * pass against a clock starting at zero and fail here.
     */
    #[Test]
    public function theBudgetIsMeasuredAsADifferenceNotAsAnAbsoluteReading(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 30);

        self::assertEqualsWithDelta(30.0, $deadline->remainingSeconds(), 0.001);
    }
}
