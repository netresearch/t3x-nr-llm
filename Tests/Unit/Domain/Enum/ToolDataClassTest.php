<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Enum test: declared #[CoversNothing] per the repo convention, since an enum
 * is not a valid coverage target.
 */
#[CoversNothing]
final class ToolDataClassTest extends TestCase
{
    #[Test]
    public function theLadderIsTotalAndStrictlyIncreasing(): void
    {
        $ranks = array_map(static fn(ToolDataClass $c): int => $c->rank(), ToolDataClass::cases());

        self::assertSame($ranks, array_values(array_unique($ranks)), 'No two classes may share a rank.');
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks, 'cases() must be declared least- to most-sensitive.');
    }

    #[Test]
    public function isAtMostIsReflexiveAndOrdered(): void
    {
        foreach (ToolDataClass::cases() as $class) {
            self::assertTrue($class->isAtMost($class));
        }

        self::assertTrue(ToolDataClass::PUBLIC_CONTENT->isAtMost(ToolDataClass::EDITOR_CONTENT));
        self::assertFalse(ToolDataClass::SECRET_ADJACENT->isAtMost(ToolDataClass::SYSTEM_DIAGNOSTICS));
    }

    #[Test]
    public function strictestErrsUpward(): void
    {
        self::assertSame(
            ToolDataClass::SECRET_ADJACENT,
            ToolDataClass::strictest(ToolDataClass::PUBLIC_CONTENT, ToolDataClass::SECRET_ADJACENT),
        );
        self::assertSame(
            ToolDataClass::SOURCE_CODE,
            ToolDataClass::strictest(ToolDataClass::SOURCE_CODE, ToolDataClass::SOURCE_CODE),
        );
    }

    #[Test]
    public function unknownValuesResolveToNullRatherThanBeingCoerced(): void
    {
        self::assertNull(ToolDataClass::tryFromString(''));
        self::assertNull(ToolDataClass::tryFromString('publiccontent'));
        self::assertSame(ToolDataClass::PUBLIC_CONTENT, ToolDataClass::tryFromString('publicContent'));
    }
}
