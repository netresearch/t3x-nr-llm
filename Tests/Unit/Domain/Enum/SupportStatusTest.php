<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SupportStatusTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['full', 'partial'], SupportStatus::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(SupportStatus::isValid('full'));
        self::assertFalse(SupportStatus::isValid('bogus'));
    }

    #[Test]
    public function tryFromStringReturnsNullForUnknown(): void
    {
        self::assertNull(SupportStatus::tryFromString('bogus'));
        self::assertSame(SupportStatus::FULL, SupportStatus::tryFromString('full'));
    }
}
