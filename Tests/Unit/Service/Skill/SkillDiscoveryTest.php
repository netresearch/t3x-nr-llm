<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillDiscovery::class)]
final class SkillDiscoveryTest extends TestCase
{
    #[Test]
    public function discoversRootAndNestedSkillFiles(): void
    {
        $tree = [
            'SKILL.md',
            'skills/alpha/SKILL.md',
            'skills/alpha/references/extra.md',
            '.claude/skills/beta/SKILL.md',
            'README.md',
            'plugin-x/skills/gamma/SKILL.md',
        ];
        $found = (new SkillDiscovery())->discover($tree);
        self::assertSame(
            ['SKILL.md', 'skills/alpha/SKILL.md', '.claude/skills/beta/SKILL.md', 'plugin-x/skills/gamma/SKILL.md'],
            $found,
        );
    }

    #[Test]
    public function ignoresNonSkillMarkdownAndAssetDirs(): void
    {
        $tree = ['skills/alpha/references/SKILL.md.txt', 'docs/SKILL.mdx'];
        self::assertSame([], (new SkillDiscovery())->discover($tree));
    }
}
