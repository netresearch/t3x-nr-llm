<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolEgressScope;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ToolEgressScope lives in Classes/Domain/Enum, which is excluded from the
// coverage source set, so a covered run rejects #[CoversClass(ToolEgressScope::class)]
// as "not a valid target for code coverage" — a warning that fails the run under
// failOnWarning (and aborts Infection's initial run). Declare CoversNothing, per
// the repo convention for enum tests.
#[CoversNothing]
final class ToolEgressScopeTest extends TestCase
{
    #[Test]
    public function valuesListsEveryCase(): void
    {
        self::assertSame(['none', 'own_site'], ToolEgressScope::values());
    }

    #[Test]
    public function isValidDistinguishesKnownFromUnknown(): void
    {
        self::assertTrue(ToolEgressScope::isValid('own_site'));
        self::assertFalse(ToolEgressScope::isValid('any'));
        self::assertFalse(ToolEgressScope::isValid(''));
    }

    #[Test]
    public function onlyNonNoneScopesPermitEgress(): void
    {
        self::assertFalse(ToolEgressScope::NONE->permitsEgress());
        self::assertTrue(ToolEgressScope::OWN_SITE->permitsEgress());
    }
}
