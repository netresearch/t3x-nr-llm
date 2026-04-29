<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageRole::class)]
final class MessageRoleTest extends TestCase
{
    #[Test]
    public function valuesListsEveryWireString(): void
    {
        self::assertSame(
            ['system', 'user', 'assistant', 'tool'],
            MessageRole::values(),
        );
    }

    #[Test]
    public function isValidReturnsTrueForKnownRoles(): void
    {
        self::assertTrue(MessageRole::isValid('system'));
        self::assertTrue(MessageRole::isValid('user'));
        self::assertTrue(MessageRole::isValid('assistant'));
        self::assertTrue(MessageRole::isValid('tool'));
    }

    #[Test]
    public function isValidReturnsFalseForUnknownStrings(): void
    {
        self::assertFalse(MessageRole::isValid(''));
        self::assertFalse(MessageRole::isValid('USER'));
        self::assertFalse(MessageRole::isValid('moderator'));
        self::assertFalse(MessageRole::isValid('user '));
    }

    #[Test]
    public function tryFromStringMatchesBuiltInTryFrom(): void
    {
        self::assertSame(MessageRole::User, MessageRole::tryFromString('user'));
        self::assertNull(MessageRole::tryFromString('moderator'));
    }
}
