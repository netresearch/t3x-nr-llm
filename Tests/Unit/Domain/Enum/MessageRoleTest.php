<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `MessageRole` is a backed enum and `Classes/Domain/Enum/` is excluded from
 * the coverage source set in `Build/phpunit.xml` (PHPUnit 12 cannot attribute
 * coverage to enum classes). The behaviour is still tested below — we just
 * cannot claim coverage for it via `#[CoversClass]`. Use `#[CoversNothing]`
 * to silence the "not a valid target for code coverage" warning under
 * `--coverage` runs (which `failOnWarning=true` would otherwise turn fatal,
 * breaking Infection's initial test suite).
 */
#[CoversNothing]
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
        self::assertSame(MessageRole::USER, MessageRole::tryFromString('user'));
        self::assertNull(MessageRole::tryFromString('moderator'));
    }
}
