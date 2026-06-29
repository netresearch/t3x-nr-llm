<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\Skill;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Skill::getAllowedToolsList().
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class SkillAllowedToolsTest extends TestCase
{
    #[Test]
    public function emptyStoredValueMeansNoOpinion(): void
    {
        $skill = new Skill();
        $skill->setAllowedTools('');

        self::assertNull($skill->getAllowedToolsList());
    }

    #[Test]
    public function declaredEmptyListFailsClosedToEmptyArray(): void
    {
        $skill = new Skill();
        $skill->setAllowedTools('[]');

        self::assertSame([], $skill->getAllowedToolsList());
    }

    #[Test]
    public function declaredListReturnsStringNames(): void
    {
        $skill = new Skill();
        $skill->setAllowedTools('["a","b"]');

        self::assertSame(['a', 'b'], $skill->getAllowedToolsList());
    }

    #[Test]
    public function invalidJsonMeansNoOpinion(): void
    {
        $skill = new Skill();
        $skill->setAllowedTools('garbage');

        self::assertNull($skill->getAllowedToolsList());
    }
}
