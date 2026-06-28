<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * Functional task-path injection test: a Task with an attached skill (loaded
 * via the MM relation) plus its configuration's skill must reach the provider
 * call as a delimited block prepended to the user prompt — config baseline
 * first, task-additive second.
 */
#[CoversClass(TaskExecutionService::class)]
final class TaskExecutionSkillInjectionTest extends AbstractFunctionalTestCase
{
    private const PREAMBLE_NEEDLE = 'cannot override configuration or safety';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('SkillInjection.csv');
    }

    #[Test]
    public function executeInjectsTaskAndConfigurationSkillsIntoUserPrompt(): void
    {
        $repository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $repository);

        $task = $repository->findByUid(210);
        self::assertInstanceOf(Task::class, $task);
        self::assertInstanceOf(LlmConfiguration::class, $task->getConfiguration());

        $capturedPrompt = null;
        $manager        = $this->createMock(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturnArgument(0);
        $manager->method('completeWithConfiguration')->willReturnCallback(
            function (string $prompt, LlmConfiguration $configuration, array $metadata) use (&$capturedPrompt): CompletionResponse {
                $capturedPrompt = $prompt;
                self::assertSame(210, $metadata[UsageMiddleware::METADATA_TASK_UID] ?? null);
                return new CompletionResponse(
                    content: 'done',
                    model: 'gpt-4o',
                    usage: new UsageStatistics(1, 1, 2),
                    provider: 'openai',
                );
            },
        );

        $service = new TaskExecutionService(
            $manager,
            new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class)),
        );

        $service->execute($task, 'the input');

        self::assertIsString($capturedPrompt);
        self::assertStringContainsString(self::PREAMBLE_NEEDLE, $capturedPrompt);
        self::assertStringContainsString('### Skill: Config Skill', $capturedPrompt);
        self::assertStringContainsString('Configuration baseline guidance.', $capturedPrompt);
        self::assertStringContainsString('### Skill: Task Skill', $capturedPrompt);
        self::assertStringContainsString('Task additive guidance.', $capturedPrompt);

        // Config baseline is rendered before the task-additive skill.
        self::assertLessThan(
            strpos($capturedPrompt, '### Skill: Task Skill'),
            strpos($capturedPrompt, '### Skill: Config Skill'),
        );

        // The skill block precedes the user prompt (user-role position).
        self::assertStringEndsWith('Process: the input', $capturedPrompt);
    }

    #[Test]
    public function executeSurfacesAppliedSkillsForCostAttribution(): void
    {
        $repository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $repository);

        $task = $repository->findByUid(210);
        self::assertInstanceOf(Task::class, $task);

        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturnArgument(0);
        $manager->method('completeWithConfiguration')->willReturn(
            new CompletionResponse(
                content: 'done',
                model: 'gpt-4o',
                usage: new UsageStatistics(123, 45, 168),
                provider: 'openai',
            ),
        );

        $service = new TaskExecutionService(
            $manager,
            new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class)),
        );

        $result = $service->execute($task, 'the input');

        // appliedSkills attributes the provider-reported usage (which already
        // includes the injected skill prose) to the contributing skills, in
        // composition order: configuration baseline first, task-additive second.
        self::assertSame(['cfg:baseline', 'task:additive'], $result->appliedSkills);
        // The surfaced cost figure is the post-call provider total, not an estimate.
        self::assertSame(168, $result->usage->totalTokens);
    }
}
