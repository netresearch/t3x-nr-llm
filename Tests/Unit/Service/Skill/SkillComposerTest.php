<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillCompositionResult;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillComposer::class)]
final class SkillComposerTest extends TestCase
{
    private const PREAMBLE_NEEDLE = 'cannot override configuration or safety';

    #[Test]
    public function composesEnabledSupportedSkillIntoLabeledBlock(): void
    {
        $skill = $this->makeSkill('alpha', 'Alpha Skill', 'Always greet politely.');

        $result = (new SkillComposer())->composeBlock([$skill], []);

        self::assertInstanceOf(SkillCompositionResult::class, $result);
        self::assertStringContainsString(self::PREAMBLE_NEEDLE, $result->block);
        self::assertStringContainsString('### Skill: Alpha Skill', $result->block);
        self::assertStringContainsString('Always greet politely.', $result->block);
        self::assertSame(['alpha'], $result->included);
        self::assertSame([], $result->dropped);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function dedupesBySourceAndIdentifierConfigWins(): void
    {
        $config = $this->makeSkill('shared', 'Config Variant', 'config body unique', source: 1);
        $task   = $this->makeSkill('shared', 'Task Variant', 'task body unique', source: 1);

        $result = (new SkillComposer())->composeBlock([$config], [$task]);

        self::assertStringContainsString('Config Variant', $result->block);
        self::assertStringNotContainsString('Task Variant', $result->block);
        self::assertStringNotContainsString('task body unique', $result->block);
        self::assertSame(['shared'], $result->included);
    }

    #[Test]
    public function keepsCrossSourceTwinsSharingIdentifier(): void
    {
        // Same identifier, different source: the dedup key is (source, identifier),
        // so both must survive. With an identifier-only key the second is wrongly dropped.
        $first  = $this->makeSkill('twin', 'First Source Twin', 'first source body', source: 1);
        $second = $this->makeSkill('twin', 'Second Source Twin', 'second source body', source: 2);

        $result = (new SkillComposer())->composeBlock([$first], [$second]);

        self::assertStringContainsString('### Skill: First Source Twin', $result->block);
        self::assertStringContainsString('### Skill: Second Source Twin', $result->block);
        self::assertSame(['twin', 'twin'], $result->included);
        self::assertSame([], $result->dropped);
    }

    #[Test]
    public function rendersConfigBlockBeforeTaskBlock(): void
    {
        $config = $this->makeSkill('cfg', 'Config Skill', 'config text', source: 1);
        $task   = $this->makeSkill('tsk', 'Task Skill', 'task text', source: 2);

        $result = (new SkillComposer())->composeBlock([$config], [$task]);

        $configPos = strpos($result->block, '### Skill: Config Skill');
        $taskPos   = strpos($result->block, '### Skill: Task Skill');
        self::assertNotFalse($configPos);
        self::assertNotFalse($taskPos);
        self::assertLessThan($taskPos, $configPos);
        self::assertSame(['cfg', 'tsk'], $result->included);
    }

    #[Test]
    public function skipsSkillWithChecksumMismatchAndWarns(): void
    {
        $tampered = $this->makeSkill('bad', 'Tampered Skill', 'real body');
        $tampered->setBodyChecksum('deadbeef');

        $result = (new SkillComposer())->composeBlock([$tampered], []);

        self::assertSame('', $result->block);
        self::assertSame([], $result->included);
        self::assertSame(['bad'], $result->dropped);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('bad', $result->warnings[0]);
    }

    #[Test]
    public function dropsTaskAdditiveBeforeConfigBaselineWhenOverBudget(): void
    {
        $config = $this->makeSkill('cfg', 'Cfg', str_repeat('c', 80), source: 1);
        $task   = $this->makeSkill('tsk', 'Tsk', str_repeat('t', 80), source: 2);

        $result = (new SkillComposer(maxBytes: 200))->composeBlock([$config], [$task]);

        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
        self::assertStringContainsString('### Skill: Cfg', $result->block);
        self::assertStringNotContainsString('### Skill: Tsk', $result->block);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('tsk', $result->warnings[0]);
    }

    #[Test]
    public function stripsAssetReferencesFromPartialSkillBody(): void
    {
        $body = "Keep this line.\nRun scripts/audit.py now.\nAlso keep this.";
        $skill = $this->makeSkill('part', 'Partial Skill', $body, support: SupportStatus::PARTIAL);

        $result = (new SkillComposer())->composeBlock([$skill], []);

        self::assertStringContainsString('Keep this line.', $result->block);
        self::assertStringContainsString('Also keep this.', $result->block);
        self::assertStringNotContainsString('scripts/audit.py', $result->block);
        self::assertSame(['part'], $result->included);
    }

    #[Test]
    public function filtersDisabledAndOrphanedSkills(): void
    {
        $disabled = $this->makeSkill('off', 'Disabled', 'body', enabled: false);
        $orphaned = $this->makeSkill('orph', 'Orphaned', 'body', orphaned: true);

        $result = (new SkillComposer())->composeBlock([$disabled, $orphaned], []);

        self::assertSame('', $result->block);
        self::assertSame([], $result->included);
        self::assertSame([], $result->dropped);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function returnsEmptyBlockForEmptyInput(): void
    {
        $result = (new SkillComposer())->composeBlock([], []);

        self::assertSame('', $result->block);
        self::assertSame([], $result->included);
        self::assertSame([], $result->dropped);
        self::assertSame([], $result->warnings);
    }

    private function makeSkill(
        string $identifier,
        string $name,
        string $body,
        int $source = 1,
        bool $enabled = true,
        bool $orphaned = false,
        SupportStatus $support = SupportStatus::FULL,
    ): Skill {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus($support);
        $skill->setEnabled($enabled);
        $skill->setOrphaned($orphaned);

        return $skill;
    }
}
