<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
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

    private function setUid(Task $task, int $uid): void
    {
        $prop = new ReflectionProperty($task, 'uid');
        $prop->setValue($task, $uid);
    }
}
