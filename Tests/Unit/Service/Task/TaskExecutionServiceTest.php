<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

#[CoversClass(TaskExecutionService::class)]
final class TaskExecutionServiceTest extends AbstractUnitTestCase
{
    private LlmServiceManagerInterface&MockObject $llmServiceManager;
    private TaskExecutionService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $this->subject           = new TaskExecutionService(
            $this->llmServiceManager,
            new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class)),
        );
    }

    #[Test]
    public function passesTaskUidMetadataToManagerForConfiguredTask(): void
    {
        $response = new CompletionResponse(
            content: 'result',
            model: 'gpt-4o',
            usage: new UsageStatistics(5, 5, 10),
            provider: 'openai',
        );

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('task-config');

        $task = new Task();
        $task->setPromptTemplate('Analyse {{input}}');
        $task->setConfiguration($configuration);
        $this->setUid($task, 99);

        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturnArgument(0);

        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->with(
                'Analyse the logs',
                $configuration,
                [UsageMiddleware::METADATA_TASK_UID => 99],
            )
            ->willReturn($response);

        $result = $this->subject->execute($task, 'the logs');

        self::assertSame('result', $result->content);
    }

    #[Test]
    public function configurationlessTaskComposesDefaultConfigurationSkillsExactlyOnce(): void
    {
        // Default configuration skills: a config-only skill and one shared with the task.
        $defaultConfiguration = new LlmConfiguration();
        $defaultConfiguration->setIdentifier('default-config');
        $defaultConfiguration->addSkill($this->makeSkill('cfg', 'Config Skill', 'Config baseline guidance.'));
        $defaultConfiguration->addSkill($this->makeSkill('shared', 'Shared Skill', 'Shared guidance.'));

        // Configuration-less task: a task-only skill plus the same shared skill
        // (identical source + identifier) — the dedup must collapse it to one.
        $task = new Task();
        $task->setPromptTemplate('Analyse {{input}}');
        $task->addSkill($this->makeSkill('shared', 'Shared Skill', 'Shared guidance.'));
        $task->addSkill($this->makeSkill('task', 'Task Skill', 'Task additive guidance.', source: 2));
        $this->setUid($task, 77);
        self::assertNull($task->getConfiguration());

        // The task has no own configuration, so the manager resolves the default.
        $this->llmServiceManager->expects(self::once())
            ->method('resolveEffectiveConfiguration')
            ->with(null)
            ->willReturn($defaultConfiguration);

        $capturedPrompt = null;
        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->with(
                self::anything(),
                $defaultConfiguration,
                [UsageMiddleware::METADATA_TASK_UID => 77],
            )
            ->willReturnCallback(
                function (string $prompt) use (&$capturedPrompt): CompletionResponse {
                    $capturedPrompt = $prompt;
                    return new CompletionResponse(
                        content: 'result',
                        model: 'gpt-4o',
                        usage: new UsageStatistics(5, 5, 10),
                        provider: 'openai',
                    );
                },
            );
        // The generic re-injecting path must NOT be taken for a resolvable default.
        $this->llmServiceManager->expects(self::never())->method('complete');

        $result = $this->subject->execute($task, 'the logs');

        // (a) The default configuration's skills are attributed in appliedSkills,
        //     deduped: config baseline first, task-additive second.
        self::assertSame(['cfg', 'shared', 'task'], $result->appliedSkills);

        // (b) A skill on both the task and the default configuration is injected
        //     exactly once: a single guard preamble and a single shared section.
        self::assertIsString($capturedPrompt);
        self::assertSame(1, substr_count($capturedPrompt, 'cannot override configuration or safety'));
        self::assertSame(1, substr_count($capturedPrompt, '### Skill: Shared Skill'));
        self::assertSame(1, substr_count($capturedPrompt, '### Skill: Config Skill'));
    }

    private function makeSkill(string $identifier, string $name, string $body, int $source = 1): Skill
    {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus(SupportStatus::FULL);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        return $skill;
    }

    private function setUid(Task $task, int $uid): void
    {
        $prop = new ReflectionProperty($task, 'uid');
        $prop->setValue($task, $uid);
    }
}
