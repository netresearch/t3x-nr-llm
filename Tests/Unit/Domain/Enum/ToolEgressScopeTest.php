<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolEgressScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolEgressScope::class)]
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
