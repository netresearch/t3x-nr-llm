<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GuardrailVerdictTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['allow', 'redact', 'retry', 'require_approval', 'deny'], GuardrailVerdict::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(GuardrailVerdict::isValid('deny'));
        self::assertTrue(GuardrailVerdict::isValid('require_approval'));
        self::assertFalse(GuardrailVerdict::isValid('bogus'));
    }
}
