<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\TaskRunRequestFactory;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskRunRequestFactory::class)]
final class TaskRunRequestFactoryTest extends TestCase
{
    #[Test]
    public function aTaskRunOffersNoToolAndHoldsThePrompt(): void
    {
        $request = $this->factory()->build($this->task(), 'hello', $this->actor());

        self::assertNotNull($request);
        self::assertSame([], $request->allowedToolNames);
        self::assertSame('Say hello', $request->messages[0]->content ?? null);
        self::assertSame('task:say', $request->origin);
    }

    #[Test]
    public function usesTheConfigurationTheManagerResolves(): void
    {
        $configuration = new LlmConfiguration();

        $request = $this->factory($configuration)->build($this->task(), 'x', $this->actor());

        self::assertSame($configuration, $request?->configuration);
    }

    #[Test]
    public function answersNullWithoutAConfiguration(): void
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturn(null);

        self::assertNull((new TaskRunRequestFactory($manager))->build($this->task(), 'x', $this->actor()));
    }

    #[Test]
    public function aJsonTaskAsksForJsonTheWayTheInteractivePathDoes(): void
    {
        $task = $this->task();
        $task->setOutputFormat(Task::OUTPUT_JSON);

        $request = $this->factory()->build($task, 'x', $this->actor());

        self::assertSame('json', $request?->options?->getResponseFormat());
        self::assertSame('Say x' . TaskExecutionService::JSON_INSTRUCTION, $request->messages[0]->content ?? null);
    }

    #[Test]
    public function aPlainTaskAsksForNoOutputFormat(): void
    {
        self::assertNull($this->factory()->build($this->task(), 'x', $this->actor())?->options?->getResponseFormat());
    }

    #[Test]
    public function theTaskUidTravelsWithTheRun(): void
    {
        $task = $this->task();
        $task->_setProperty('uid', 7);

        self::assertSame(7, $this->factory()->build($task, 'x', $this->actor())?->options?->getTaskUid());
    }

    #[Test]
    public function aNewTaskCarriesNoUid(): void
    {
        self::assertNull($this->factory()->build($this->task(), 'x', $this->actor())?->options?->getTaskUid());
    }

    #[Test]
    public function aTaskWithoutSkillsLeavesTheAugmentationNull(): void
    {
        self::assertNull($this->factory()->build($this->task(), 'x', $this->actor())?->augmentation);
    }

    #[Test]
    public function theTasksSkillsAreForcedOnTheRun(): void
    {
        $skill = new Skill();
        $task  = $this->task();
        $task->addSkill($skill);

        $augmentation = $this->factory()->build($task, 'x', $this->actor())?->augmentation;

        self::assertInstanceOf(RunAugmentation::class, $augmentation);
        self::assertSame([$skill], $augmentation->forcedSkills);
    }

    #[Test]
    public function aBackendUsersUidCarriesTheBudgetAServiceAccountDoesNot(): void
    {
        self::assertSame(4, $this->factory()->build($this->task(), 'x', AiActorContext::backendUser(4))?->options?->getBeUserUid());
        self::assertNull($this->factory()->build($this->task(), 'x', $this->actor())?->options?->getBeUserUid());
    }

    private function actor(): AiActorContext
    {
        return AiActorContext::serviceAccount('test', [ServiceAccountScope::CONFIGURATION_USE]);
    }

    private function task(): Task
    {
        $task = new Task();
        $task->setIdentifier('say');
        $task->setPromptTemplate('Say {{input}}');

        return $task;
    }

    private function factory(?LlmConfiguration $configuration = null): TaskRunRequestFactory
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturn($configuration ?? new LlmConfiguration());

        return new TaskRunRequestFactory($manager);
    }
}
