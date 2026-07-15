<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// SkillTrustLevel lives in Classes/Domain/Enum, which is excluded from the
// coverage source set, so a covered run rejects #[CoversClass(SkillTrustLevel::class)]
// as "not a valid target for code coverage" — a warning that fails the run under
// failOnWarning (and aborts Infection's initial run). Declare CoversNothing, per
// the repo convention for enum tests.
#[CoversNothing]
final class SkillTrustLevelTest extends TestCase
{
    #[Test]
    public function valuesListsEveryCase(): void
    {
        self::assertSame(['untrusted', 'community', 'verified', 'first_party'], SkillTrustLevel::values());
    }

    #[Test]
    public function isValidAcceptsKnownAndRejectsUnknown(): void
    {
        self::assertTrue(SkillTrustLevel::isValid('verified'));
        self::assertFalse(SkillTrustLevel::isValid('trusted'));
        self::assertFalse(SkillTrustLevel::isValid(''));
    }

    #[Test]
    public function tryFromStringResolvesOrReturnsNull(): void
    {
        self::assertSame(SkillTrustLevel::VERIFIED, SkillTrustLevel::tryFromString('verified'));
        self::assertNull(SkillTrustLevel::tryFromString('nope'));
    }

    #[Test]
    public function fromStringOrUntrustedFailsClosedToLowest(): void
    {
        self::assertSame(SkillTrustLevel::FIRST_PARTY, SkillTrustLevel::fromStringOrUntrusted('first_party'));
        self::assertSame(SkillTrustLevel::UNTRUSTED, SkillTrustLevel::fromStringOrUntrusted('bogus'));
        self::assertSame(SkillTrustLevel::UNTRUSTED, SkillTrustLevel::fromStringOrUntrusted(''));
    }

    #[Test]
    public function rankIsMonotonic(): void
    {
        self::assertSame(0, SkillTrustLevel::UNTRUSTED->rank());
        self::assertSame(1, SkillTrustLevel::COMMUNITY->rank());
        self::assertSame(2, SkillTrustLevel::VERIFIED->rank());
        self::assertSame(3, SkillTrustLevel::FIRST_PARTY->rank());
    }

    #[Test]
    public function satisfiesComparesAgainstMinimum(): void
    {
        self::assertTrue(SkillTrustLevel::VERIFIED->satisfies(SkillTrustLevel::VERIFIED));
        self::assertTrue(SkillTrustLevel::FIRST_PARTY->satisfies(SkillTrustLevel::VERIFIED));
        self::assertFalse(SkillTrustLevel::COMMUNITY->satisfies(SkillTrustLevel::VERIFIED));
        self::assertFalse(SkillTrustLevel::UNTRUSTED->satisfies(SkillTrustLevel::COMMUNITY));
        // Everything satisfies the lowest bar.
        self::assertTrue(SkillTrustLevel::UNTRUSTED->satisfies(SkillTrustLevel::UNTRUSTED));
    }
}
