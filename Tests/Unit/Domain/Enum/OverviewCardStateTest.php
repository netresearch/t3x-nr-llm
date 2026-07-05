<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\OverviewCardState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit 12 cannot attribute coverage to enums, so this is marked CoversNothing.
 */
#[CoversNothing]
final class OverviewCardStateTest extends TestCase
{
    #[Test]
    public function isValidAcceptsKnownValuesAndRejectsOthers(): void
    {
        self::assertTrue(OverviewCardState::isValid('ready'));
        self::assertTrue(OverviewCardState::isValid('next'));
        self::assertTrue(OverviewCardState::isValid('empty'));
        self::assertTrue(OverviewCardState::isValid('locked'));
        self::assertTrue(OverviewCardState::isValid('neutral'));
        self::assertFalse(OverviewCardState::isValid('bogus'));
        self::assertFalse(OverviewCardState::isValid(''));
    }

    #[Test]
    public function cssClassMapsEachState(): void
    {
        self::assertSame('is-ready', OverviewCardState::Ready->cssClass());
        self::assertSame('is-next', OverviewCardState::Next->cssClass());
        self::assertSame('is-empty', OverviewCardState::EmptyState->cssClass());
        self::assertSame('is-locked', OverviewCardState::Locked->cssClass());
        self::assertSame('', OverviewCardState::Neutral->cssClass());
    }
}
