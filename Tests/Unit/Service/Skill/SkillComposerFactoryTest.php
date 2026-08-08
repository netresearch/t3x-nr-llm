<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The factory is the only production construction path for {@see SkillComposer}
 * (``Configuration/Services.yaml`` builds the shared service through it), so the
 * byte budget is asserted HERE and not only against a directly constructed
 * composer — a composer built by hand in a test would pass while the wired one
 * ignored the setting.
 */
#[CoversClass(SkillComposerFactory::class)]
final class SkillComposerFactoryTest extends TestCase
{
    /** Comfortably over the 24 000-byte default once two of them are composed. */
    private const LARGE_BODY_BYTES = 20000;

    /** Two of these plus the framing fit under the default but not under a 900-byte budget. */
    private const SMALL_BODY_BYTES = 400;

    #[Test]
    public function configuredBudgetIsAppliedToTheComposedBlock(): void
    {
        $composer = $this->factoryWith(['maxBytes' => '900'])->create();

        $result = $composer->composeBlock(...$this->smallPair());

        // The pair fits under the default but not under 900: the configured
        // value reached the composer.
        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
        self::assertStringNotContainsString('### Skill: Tsk', $result->block);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('900-byte budget', $result->warnings[0]);
    }

    #[Test]
    public function integerTypedBudgetIsAccepted(): void
    {
        // int+-typed template fields come back as int when set programmatically
        // rather than through the install tool.
        $result = $this->factoryWith(['maxBytes' => 900])->create()->composeBlock(...$this->smallPair());

        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
    }

    #[Test]
    public function configuredBudgetAboveTheBlockDropsNothing(): void
    {
        $result = $this->factoryWith(['maxBytes' => '24000'])->create()->composeBlock(...$this->smallPair());

        self::assertSame(['cfg', 'tsk'], $result->included);
        self::assertSame([], $result->dropped);
        self::assertSame([], $result->warnings);
    }

    /**
     * A value that cannot be read as a positive byte count must leave the
     * default ceiling standing. Removing the cap is never the fallback: an
     * emptied or fat-fingered field would otherwise put an unbounded skill
     * block on the wire.
     *
     * @param array<string, mixed> $skillsConfig
     */
    #[Test]
    #[DataProvider('unusableBudgetValues')]
    public function unusableBudgetValueKeepsTheDefaultCap(array $skillsConfig): void
    {
        $result = $this->factoryWith($skillsConfig)->create()->composeBlock(...$this->oversizedPair());

        // Over the 24 000-byte default, so the task-additive skill is dropped.
        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
        self::assertStringContainsString(
            sprintf('%d-byte budget', SkillComposer::DEFAULT_MAX_BYTES),
            $result->warnings[0],
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function unusableBudgetValues(): iterable
    {
        yield 'key absent'   => [[]];
        yield 'empty string' => [['maxBytes' => '']];
        yield 'non-numeric'  => [['maxBytes' => 'plenty']];
        yield 'zero'         => [['maxBytes' => '0']];
        yield 'negative'     => [['maxBytes' => '-500']];
        yield 'null'         => [['maxBytes' => null]];
        yield 'array'        => [['maxBytes' => ['24000']]];
    }

    #[Test]
    public function missingSkillsSectionKeepsTheDefaultCap(): void
    {
        // Not merely an absent maxBytes key: the whole skills.* section missing,
        // which is what an instance that never opened the settings module has.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['telemetry' => ['enabled' => '1']]);

        $result = (new SkillComposerFactory($extensionConfiguration))
            ->create()
            ->composeBlock(...$this->oversizedPair());

        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
    }

    #[Test]
    public function unusableBudgetValueStillComposesWhatFitsUnderTheDefault(): void
    {
        // The counterpart to the assertion above: the fallback is the 24 000
        // default, not a cap so small that everything is dropped.
        $result = $this->factoryWith(['maxBytes' => 'plenty'])->create()->composeBlock(...$this->smallPair());

        self::assertSame(['cfg', 'tsk'], $result->included);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function unreadableConfigurationKeepsTheDefaultCap(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1784750101),
        );

        $result = (new SkillComposerFactory($extensionConfiguration))
            ->create()
            ->composeBlock(...$this->oversizedPair());

        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
    }

    #[Test]
    public function defaultCapIsEnforcedWhenNothingIsConfigured(): void
    {
        // Guards the pre-existing behaviour the constructor default already
        // provided: an unconfigured instance is capped, not uncapped.
        $result = $this->factoryWith([])->create()->composeBlock(...$this->oversizedPair());

        self::assertSame(['cfg'], $result->included);
        self::assertSame(['tsk'], $result->dropped);
    }

    #[Test]
    public function configBaselineIsRenderedBeforeTaskAdditiveSkills(): void
    {
        // Characterisation: the drop order and the render order are the same
        // property, and adding the budget read must not disturb either.
        $result = $this->factoryWith(['maxBytes' => '24000'])->create()->composeBlock(
            [$this->makeSkill('cfg', 'Cfg', 'config text', source: 1)],
            [$this->makeSkill('tsk', 'Tsk', 'task text', source: 2)],
        );

        $configPos = strpos($result->block, '### Skill: Cfg');
        $taskPos   = strpos($result->block, '### Skill: Tsk');
        self::assertNotFalse($configPos);
        self::assertNotFalse($taskPos);
        self::assertLessThan($taskPos, $configPos);
        self::assertSame(['cfg', 'tsk'], $result->included);
    }

    #[Test]
    public function trustFloorIsStillResolvedAlongsideTheBudget(): void
    {
        // Adding the budget read must not cost the ADR-061 trust gate.
        $below = $this->makeSkill('c', 'Community', 'community body', source: 1, trust: SkillTrustLevel::COMMUNITY);
        $meets = $this->makeSkill('v', 'Verified', 'verified body', source: 2, trust: SkillTrustLevel::VERIFIED);

        $result = $this->factoryWith(['minTrustLevel' => 'verified', 'maxBytes' => '24000'])
            ->create()
            ->composeBlock([$below, $meets], []);

        self::assertSame(['v'], $result->included);
    }

    #[Test]
    public function trustFloorFallsBackIndependentlyOfAnUnusableBudget(): void
    {
        // The two reads have opposite fallback directions and must not be
        // coupled: a broken budget value leaves the configured floor intact.
        $below = $this->makeSkill('c', 'Community', 'community body', source: 1, trust: SkillTrustLevel::COMMUNITY);
        $meets = $this->makeSkill('v', 'Verified', 'verified body', source: 2, trust: SkillTrustLevel::VERIFIED);

        $result = $this->factoryWith(['minTrustLevel' => 'verified', 'maxBytes' => 'nonsense'])
            ->create()
            ->composeBlock([$below, $meets], []);

        self::assertSame(['v'], $result->included);
    }

    /**
     * A config baseline and a task-additive skill that together exceed the
     * 24 000-byte default.
     *
     * @return array{0: list<Skill>, 1: list<Skill>}
     */
    private function oversizedPair(): array
    {
        return [
            [$this->makeSkill('cfg', 'Cfg', str_repeat('c', self::LARGE_BODY_BYTES), source: 1)],
            [$this->makeSkill('tsk', 'Tsk', str_repeat('t', self::LARGE_BODY_BYTES), source: 2)],
        ];
    }

    /**
     * A pair that fits under the default ceiling but not under a 900-byte one.
     *
     * @return array{0: list<Skill>, 1: list<Skill>}
     */
    private function smallPair(): array
    {
        return [
            [$this->makeSkill('cfg', 'Cfg', str_repeat('c', self::SMALL_BODY_BYTES), source: 1)],
            [$this->makeSkill('tsk', 'Tsk', str_repeat('t', self::SMALL_BODY_BYTES), source: 2)],
        ];
    }

    /**
     * @param array<string, mixed> $skillsConfig the ``skills.*`` sub-array
     */
    private function factoryWith(array $skillsConfig): SkillComposerFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['skills' => $skillsConfig]);

        return new SkillComposerFactory($extensionConfiguration);
    }

    private function makeSkill(
        string $identifier,
        string $name,
        string $body,
        int $source = 1,
        SkillTrustLevel $trust = SkillTrustLevel::UNTRUSTED,
    ): Skill {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus(SupportStatus::FULL->value);
        $skill->setTrustLevel($trust->value);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        return $skill;
    }
}
