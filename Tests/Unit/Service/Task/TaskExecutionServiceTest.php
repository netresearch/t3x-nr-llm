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
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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
                [],
            )
            ->willReturn($response);

        $result = $this->subject->execute($task, 'the logs');

        self::assertSame('result', $result->content);
    }

    #[Test]
    public function passesBudgetUidMetadataForConfiguredTask(): void
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

        // REC #4: a positive uid rides along as budget metadata next to the
        // task attribution — the BudgetMiddleware pre-flights the user cap.
        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->with(
                'Analyse the logs',
                $configuration,
                [
                    UsageMiddleware::METADATA_TASK_UID     => 99,
                    BudgetMiddleware::METADATA_BE_USER_UID => 42,
                ],
                [],
            )
            ->willReturn($response);

        $this->subject->execute($task, 'the logs', 42);
    }

    #[Test]
    public function omitsBudgetUidMetadataForAnonymousUid(): void
    {
        $response = new CompletionResponse(
            content: 'result',
            model: 'gpt-4o',
            usage: new UsageStatistics(5, 5, 10),
            provider: 'openai',
        );

        $configuration = new LlmConfiguration();

        $task = new Task();
        $task->setPromptTemplate('Analyse {{input}}');
        $task->setConfiguration($configuration);
        $this->setUid($task, 99);

        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturnArgument(0);

        // uid 0 means "anonymous / skip the user cap" (REC #4 contract):
        // the budget key must not appear, exactly like a null uid.
        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->with(
                self::anything(),
                $configuration,
                [UsageMiddleware::METADATA_TASK_UID => 99],
                [],
            )
            ->willReturn($response);

        $this->subject->execute($task, 'the logs', 0);
    }

    #[Test]
    public function passesBudgetUidAsChatOptionsFieldWithoutConfiguration(): void
    {
        $task = new Task();
        $task->setPromptTemplate('Analyse {{input}}');
        $this->setUid($task, 77);

        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturn(null);

        // Default path: the uid travels as the ChatOptions budget field; the
        // manager builds the same budget metadata from it.
        $this->llmServiceManager->expects(self::once())
            ->method('complete')
            ->with(
                self::anything(),
                self::callback(static fn(ChatOptions $options): bool => $options->getBeUserUid() === 42),
            )
            ->willReturn(new CompletionResponse(
                content: 'result',
                model: 'gpt-4o',
                usage: new UsageStatistics(5, 5, 10),
                provider: 'openai',
            ));

        $this->subject->execute($task, 'the logs', 42);
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
                [],
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

    #[Test]
    public function jsonTaskWithConfigurationGetsJsonModeOverrideAndPromptInstruction(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('json-task-config');

        $task = new Task();
        $task->setPromptTemplate('Extract entities from {{input}}');
        $task->setConfiguration($configuration);
        $task->setOutputFormat('json');

        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturnArgument(0);

        $capturedPrompt    = null;
        $capturedOverrides = null;
        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->willReturnCallback(
                function (string $prompt, LlmConfiguration $config, array $metadata = [], array $optionOverrides = []) use (&$capturedPrompt, &$capturedOverrides): CompletionResponse {
                    $capturedPrompt    = $prompt;
                    $capturedOverrides = $optionOverrides;
                    return new CompletionResponse(
                        content: '{"ok":true}',
                        model: 'gpt-4o',
                        usage: new UsageStatistics(5, 5, 10),
                        provider: 'openai',
                    );
                },
            );

        $result = $this->subject->execute($task, 'the text');

        self::assertSame('{"ok":true}', $result->content);
        // Real JSON mode rides as an option override on the configured path (ADR-128)…
        self::assertSame(['response_format' => 'json'], $capturedOverrides);
        // …and the prompt ends with the instruction that also satisfies the
        // OpenAI-dialect "json must appear in the messages" requirement.
        self::assertIsString($capturedPrompt);
        self::assertStringEndsWith("\n\nRespond with valid JSON only. No markdown, no explanation.", $capturedPrompt);
    }

    #[Test]
    public function markdownTaskGetsNoJsonOverrideAndNoInstruction(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('md-task-config');

        $task = new Task();
        $task->setPromptTemplate('Summarise {{input}}');
        $task->setConfiguration($configuration);
        $task->setOutputFormat('markdown');

        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturnArgument(0);

        $capturedPrompt    = null;
        $capturedOverrides = null;
        $this->llmServiceManager->expects(self::once())
            ->method('completeWithConfiguration')
            ->willReturnCallback(
                function (string $prompt, LlmConfiguration $config, array $metadata = [], array $optionOverrides = []) use (&$capturedPrompt, &$capturedOverrides): CompletionResponse {
                    $capturedPrompt    = $prompt;
                    $capturedOverrides = $optionOverrides;
                    return new CompletionResponse(
                        content: 'summary',
                        model: 'gpt-4o',
                        usage: new UsageStatistics(5, 5, 10),
                        provider: 'openai',
                    );
                },
            );

        $result = $this->subject->execute($task, 'the text');

        self::assertSame('summary', $result->content);
        self::assertSame([], $capturedOverrides);
        self::assertIsString($capturedPrompt);
        self::assertStringNotContainsString('Respond with valid JSON only.', $capturedPrompt);
    }

    #[Test]
    public function configurationlessJsonTaskGetsJsonResponseFormatOnChatOptions(): void
    {
        $task = new Task();
        $task->setPromptTemplate('Extract entities from {{input}}');
        $task->setOutputFormat('json');
        self::assertNull($task->getConfiguration());

        // No resolvable configuration: the generic complete() path is taken.
        $this->llmServiceManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $this->llmServiceManager->expects(self::never())->method('completeWithConfiguration');

        $capturedOptions = null;
        $this->llmServiceManager->expects(self::once())
            ->method('complete')
            ->willReturnCallback(
                function (string $prompt, ?ChatOptions $options = null) use (&$capturedOptions): CompletionResponse {
                    $capturedOptions = $options;
                    return new CompletionResponse(
                        content: '{"ok":true}',
                        model: 'gpt-4o',
                        usage: new UsageStatistics(5, 5, 10),
                        provider: 'openai',
                    );
                },
            );

        $result = $this->subject->execute($task, 'the text');

        self::assertSame('{"ok":true}', $result->content);
        self::assertInstanceOf(ChatOptions::class, $capturedOptions);
        self::assertSame('json', $capturedOptions->getResponseFormat());
    }

    private function makeSkill(string $identifier, string $name, string $body, int $source = 1): Skill
    {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus(SupportStatus::FULL->value);
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
