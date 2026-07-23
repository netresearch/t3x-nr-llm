<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ToolEffectTest extends TestCase
{
    /**
     * @return iterable<string, array{ToolEffect, bool, bool}>
     */
    public static function effects(): iterable
    {
        // effect => [isWrite, isSafeToRetry]
        yield 'read-only'           => [ToolEffect::READ_ONLY, false, true];
        yield 'idempotent write'    => [ToolEffect::IDEMPOTENT_WRITE, true, true];
        yield 'non-idempotent write' => [ToolEffect::NON_IDEMPOTENT_WRITE, true, false];
    }

    #[Test]
    #[DataProvider('effects')]
    public function classifiesWriteAndRetrySafety(ToolEffect $effect, bool $isWrite, bool $isSafeToRetry): void
    {
        self::assertSame($isWrite, $effect->isWrite());
        self::assertSame($isSafeToRetry, $effect->isSafeToRetry());
    }

    #[Test]
    public function onlyTheNonIdempotentWriteIsUnsafeToRetry(): void
    {
        // The retry guard hinges on exactly one case: an at-least-once queue may
        // repeat everything except a non-idempotent write.
        $unsafe = array_filter(ToolEffect::cases(), static fn(ToolEffect $e): bool => !$e->isSafeToRetry());

        self::assertSame([ToolEffect::NON_IDEMPOTENT_WRITE], array_values($unsafe));
    }
}
