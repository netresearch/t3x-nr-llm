<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AllowedToolsResolver::class)]
final class AllowedToolsResolverTest extends TestCase
{
    private function resolver(): AllowedToolsResolver
    {
        // A real (pure) SkillComposer: effectiveSkills() has no side effects, so the
        // resolver is exercised against the genuine enabled/non-orphaned/dedup selection.
        return new AllowedToolsResolver(new SkillComposer());
    }

    private function skill(
        string $identifier,
        string $allowedTools,
        bool $enabled = true,
        bool $orphaned = false,
        int $source = 1,
    ): Skill {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setAllowedTools($allowedTools);
        $skill->setEnabled($enabled);
        $skill->setOrphaned($orphaned);

        return $skill;
    }

    #[Test]
    public function returnsNullWhenNoEffectiveSkillDeclares(): void
    {
        $config = new LlmConfiguration();
        $config->addSkill($this->skill('a', ''));
        $config->addSkill($this->skill('b', ''));

        self::assertNull($this->resolver()->resolve($config));
    }

    #[Test]
    public function returnsDeclaredNamesWhenOneDeclaresAndAnotherIsAbsent(): void
    {
        // Declarer on the configuration, an absent (no-opinion) skill on the task:
        // exercises both the config and the task skill-list paths.
        $config = new LlmConfiguration();
        $config->addSkill($this->skill('a', '["fetch_logs"]'));

        $task = new Task();
        $task->addSkill($this->skill('b', ''));

        self::assertSame(['fetch_logs'], $this->resolver()->resolve($config, $task));
    }

    #[Test]
    public function loneDeclaredEmptyListFailsClosedToEmptyArray(): void
    {
        $config = new LlmConfiguration();
        $config->addSkill($this->skill('a', '[]'));

        self::assertSame([], $this->resolver()->resolve($config));
    }

    #[Test]
    public function disabledDeclarerIsExcludedSoNoOpinionRemains(): void
    {
        $config = new LlmConfiguration();
        $config->addSkill($this->skill('a', '["x"]', enabled: false));
        $config->addSkill($this->skill('b', '', enabled: true));

        self::assertNull($this->resolver()->resolve($config));
    }

    #[Test]
    public function unionsDeclaredNamesAcrossDeclarers(): void
    {
        $config = new LlmConfiguration();
        $config->addSkill($this->skill('a', '["a"]'));
        $config->addSkill($this->skill('b', '["a","b"]'));

        $result = $this->resolver()->resolve($config);
        self::assertNotNull($result);
        sort($result);
        self::assertSame(['a', 'b'], $result);
    }
}
