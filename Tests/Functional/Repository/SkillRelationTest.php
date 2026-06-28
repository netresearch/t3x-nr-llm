<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional round-trip tests for the Task↔Skill and Configuration↔Skill MM relations.
 */
#[CoversClass(TaskRepository::class)]
#[CoversClass(LlmConfigurationRepository::class)]
final class SkillRelationTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('SkillRelations.csv');
    }

    #[Test]
    public function taskExposesAttachedSkillsViaMmRelation(): void
    {
        $repository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $repository);

        $task = $repository->findByUid(110);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(['First Skill', 'Second Skill'], $this->skillNames($task->getSkills()));
    }

    #[Test]
    public function configurationExposesAttachedSkillsViaMmRelation(): void
    {
        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);

        $configuration = $repository->findByUid(120);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame(['First Skill'], $this->skillNames($configuration->getSkills()));
    }

    /**
     * Collect skill names in MM-relation order (no re-sorting), so the
     * deterministic `sorting` ordering of the MM relation is asserted.
     *
     * @param iterable<Skill> $skills
     *
     * @return list<string>
     */
    private function skillNames(iterable $skills): array
    {
        $names = [];
        foreach ($skills as $skill) {
            $names[] = $skill->getName();
        }

        return $names;
    }
}
