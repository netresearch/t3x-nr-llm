<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PrivacyLevelTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedStringValues(): void
    {
        self::assertSame('none', PrivacyLevel::NONE->value);
        self::assertSame('metadata', PrivacyLevel::METADATA->value);
        self::assertSame('redacted', PrivacyLevel::REDACTED->value);
        self::assertSame('full', PrivacyLevel::FULL->value);
    }

    #[Test]
    public function valuesReturnsAllCasesInOrder(): void
    {
        self::assertSame(['none', 'metadata', 'redacted', 'full'], PrivacyLevel::values());
    }

    #[Test]
    public function isValidAcceptsKnownAndRejectsUnknown(): void
    {
        self::assertTrue(PrivacyLevel::isValid('redacted'));
        self::assertFalse(PrivacyLevel::isValid('partial'));
        self::assertFalse(PrivacyLevel::isValid(''));
    }

    #[Test]
    public function tryFromStringMapsKnownAndReturnsNullForUnknown(): void
    {
        self::assertSame(PrivacyLevel::FULL, PrivacyLevel::tryFromString('full'));
        self::assertNull(PrivacyLevel::tryFromString('nope'));
    }

    #[Test]
    public function persistsContentOnlyForRedactedAndFull(): void
    {
        self::assertFalse(PrivacyLevel::NONE->persistsContent());
        self::assertFalse(PrivacyLevel::METADATA->persistsContent());
        self::assertTrue(PrivacyLevel::REDACTED->persistsContent());
        self::assertTrue(PrivacyLevel::FULL->persistsContent());
    }

    #[Test]
    public function requiresRedactionOnlyForRedacted(): void
    {
        self::assertFalse(PrivacyLevel::NONE->requiresRedaction());
        self::assertFalse(PrivacyLevel::METADATA->requiresRedaction());
        self::assertTrue(PrivacyLevel::REDACTED->requiresRedaction());
        self::assertFalse(PrivacyLevel::FULL->requiresRedaction());
    }

    #[Test]
    public function strictestPicksTheMoreRestrictiveLevel(): void
    {
        // NONE is strictest, regardless of argument order.
        self::assertSame(PrivacyLevel::NONE, PrivacyLevel::strictest(PrivacyLevel::NONE, PrivacyLevel::FULL));
        self::assertSame(PrivacyLevel::NONE, PrivacyLevel::strictest(PrivacyLevel::FULL, PrivacyLevel::NONE));

        self::assertSame(PrivacyLevel::METADATA, PrivacyLevel::strictest(PrivacyLevel::METADATA, PrivacyLevel::REDACTED));
        self::assertSame(PrivacyLevel::REDACTED, PrivacyLevel::strictest(PrivacyLevel::REDACTED, PrivacyLevel::FULL));
        self::assertSame(PrivacyLevel::FULL, PrivacyLevel::strictest(PrivacyLevel::FULL, PrivacyLevel::FULL));
    }
}
