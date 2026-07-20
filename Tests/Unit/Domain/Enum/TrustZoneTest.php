<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TrustZoneTest extends TestCase
{
    #[Test]
    public function everyZoneDeclaresACeiling(): void
    {
        self::assertSame(ToolDataClass::SECRET_ADJACENT, TrustZone::LOCAL->maxDataClass());
        self::assertSame(ToolDataClass::SYSTEM_DIAGNOSTICS, TrustZone::PRIVATE_HOSTED->maxDataClass());
        self::assertSame(ToolDataClass::INTERNAL_CONFIGURATION, TrustZone::EXTERNAL_EU->maxDataClass());
        self::assertSame(ToolDataClass::EDITOR_CONTENT, TrustZone::EXTERNAL_GLOBAL->maxDataClass());
    }

    #[Test]
    public function permitsFollowsTheCeilingForEveryZoneClassPair(): void
    {
        foreach (TrustZone::cases() as $zone) {
            foreach (ToolDataClass::cases() as $class) {
                self::assertSame(
                    $class->rank() <= $zone->maxDataClass()->rank(),
                    $zone->permits($class),
                    sprintf('%s vs %s', $zone->value, $class->value),
                );
            }
        }
    }

    #[Test]
    public function anUnknownOrEmptyStoredValueResolvesToTheStrictestZone(): void
    {
        // An un-migrated row, or a value written by a newer version, must never
        // widen the gate.
        self::assertSame(TrustZone::EXTERNAL_GLOBAL, TrustZone::fromStringOrStrictest(''));
        self::assertSame(TrustZone::EXTERNAL_GLOBAL, TrustZone::fromStringOrStrictest('nonsense'));
        self::assertSame(TrustZone::LOCAL, TrustZone::fromStringOrStrictest('local'));
    }

    #[Test]
    public function leastTrustedPicksTheWorseZone(): void
    {
        self::assertSame(
            TrustZone::EXTERNAL_GLOBAL,
            TrustZone::leastTrusted(TrustZone::LOCAL, TrustZone::EXTERNAL_GLOBAL),
        );
        self::assertSame(
            TrustZone::EXTERNAL_EU,
            TrustZone::leastTrusted(TrustZone::EXTERNAL_EU, TrustZone::PRIVATE_HOSTED),
        );
    }
}
