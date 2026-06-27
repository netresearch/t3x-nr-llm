<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SkillSourceTypeTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['single_file', 'repo', 'marketplace'], SkillSourceType::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(SkillSourceType::isValid('repo'));
        self::assertFalse(SkillSourceType::isValid('bogus'));
    }

    #[Test]
    public function tryFromStringReturnsNullForUnknown(): void
    {
        self::assertNull(SkillSourceType::tryFromString('bogus'));
        self::assertSame(SkillSourceType::REPO, SkillSourceType::tryFromString('repo'));
    }
}
