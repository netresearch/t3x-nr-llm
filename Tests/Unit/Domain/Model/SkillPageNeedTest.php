<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\SkillPageNeed;
use Netresearch\NrLlm\Domain\Model\Skill;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The page need a skill declares (ADR-229), as a consumer reads it: the
 * declared value, or null when there is none. The default for null is the
 * consumer's, so a process skill that declares nothing is still null here.
 */
#[CoversNothing]
final class SkillPageNeedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SkillPageNeed|null}>
     */
    public static function storedValues(): iterable
    {
        yield 'required' => ['required', SkillPageNeed::REQUIRED];
        yield 'optional' => ['optional', SkillPageNeed::OPTIONAL];
        yield 'none' => ['none', SkillPageNeed::NONE];
        yield 'not declared' => ['', null];
        yield 'a value no form offers' => ['sometimes', null];
    }

    #[Test]
    #[DataProvider('storedValues')]
    public function theDeclaredValueIsExposedAsItIs(string $stored, ?SkillPageNeed $expected): void
    {
        $skill = new Skill();
        $skill->setPage($stored);

        self::assertSame($stored, $skill->getPage());
        self::assertSame($expected, $skill->getPageNeed());
    }

    #[Test]
    public function aProcessSkillThatDeclaresNothingHasNoPageNeed(): void
    {
        $skill = new Skill();
        $skill->setProcess(true);

        self::assertNull($skill->getPageNeed());
    }
}
