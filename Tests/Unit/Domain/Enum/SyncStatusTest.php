<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SyncStatusTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['never_synced', 'syncing', 'ok', 'partial', 'error'], SyncStatus::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(SyncStatus::isValid('syncing'));
        self::assertFalse(SyncStatus::isValid('bogus'));
    }

    #[Test]
    public function tryFromStringReturnsNullForUnknown(): void
    {
        self::assertNull(SyncStatus::tryFromString('bogus'));
        self::assertSame(SyncStatus::OK, SyncStatus::tryFromString('ok'));
    }
}
