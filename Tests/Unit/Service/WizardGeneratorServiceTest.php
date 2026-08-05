<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use ArrayIterator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(WizardGeneratorService::class)]
#[AllowMockObjectsWithoutExpectations]
class WizardGeneratorServiceTest extends AbstractUnitTestCase
{
    private CompletionServiceInterface&MockObject $completionService;

    private LlmConfigurationRepository&MockObject $configurationRepository;

    private ModelRepository&MockObject $modelRepository;

    private WizardGeneratorService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->completionService = $this->createMock(CompletionServiceInterface::class);
        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);

        $this->subject = new WizardGeneratorService(
            $this->completionService,
            $this->configurationRepository,
            $this->modelRepository,
            $this->createLoggerMock(),
        );
    }

    // ==================== Helper methods ====================

    private function createConfigurationWithModel(): LlmConfiguration
    {
        $model = new Model();
        $model->setModelId('gpt-5.2');
        $model->setName('GPT-5.2');

        $config = new LlmConfiguration();
        $config->_setProperty('llmModel', $model);
        $config->_setProperty('isActive', true);
        $config->_setProperty('isDefault', true);
        $config->_setProperty('systemPrompt', 'You are helpful.');

        return $config;
    }

    private function createActiveModel(string $modelId, string $name = ''): Model
    {
        $model = new Model();
        $model->setModelId($modelId);
        $model->setName($name ?: $modelId);
        $model->setIsActive(true);

        return $model;
    }

    private function stubDefaultConfig(?LlmConfiguration $config): void
    {
        $this->configurationRepository
            ->method('findDefault')
            ->willReturn($config);
    }

    private function stubNoDefaultConfig(): void
    {
        $this->configurationRepository
            ->method('findDefault')
            ->willReturn(null);
        $this->configurationRepository
            ->method('findAll')
            ->willReturn([]);
    }

    /**
     * Stub the structured completion to return $result directly — since the
     * ADR-126/128 rewrite the wizard receives the decoded, schema-validated
     * array from completeStructuredForConfiguration(), never raw content.
     *
     * @param array<string, mixed> $result
     */
    private function stubStructuredResult(array $result): void
    {
        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willReturn($result);
    }

    /**
     * Stub the structured completion to return $result and capture the outgoing
     * call (prompt, configuration, schema, options) into $captured by reference.
     *
     * @param array<string, mixed>                                                                                                 $result
     * @param array{prompt: string, configuration: LlmConfiguration, schema: array<string, mixed>, options: ChatOptions|null}|null $captured
     */
    private function stubStructuredCapturing(array $result, ?array &$captured): void
    {
        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willReturnCallback(
                function (string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options = null) use (&$captured, $result): array {
                    $captured = [
                        'prompt' => $prompt,
                        'configuration' => $configuration,
                        'schema' => $schema,
                        'options' => $options,
                    ];

                    return $result;
                },
            );
    }

    /**
     * Create a QueryResultInterface stub that iterates over the given items.
     *
     * @param array<object> $items
     *
     * @return QueryResultInterface<int, Model>
     */
    private function createQueryResultStub(array $items): QueryResultInterface
    {
        $iterator = new ArrayIterator($items);
        $stub = self::createStub(QueryResultInterface::class);
        $stub->method('current')->willReturnCallback(fn(): object => $iterator->current());
        $stub->method('key')->willReturnCallback(fn(): int|string => $iterator->key());
        $stub->method('next')->willReturnCallback(fn(): null => $iterator->next());
        $stub->method('rewind')->willReturnCallback(fn(): null => $iterator->rewind());
        $stub->method('valid')->willReturnCallback(fn(): bool => $iterator->valid());
        $stub->method('count')->willReturn(count($items));
        $stub->method('toArray')->willReturn($items);
        $stub->method('getFirst')->willReturn($items[0] ?? null);

        return $stub;
    }

    private function createBudgetExceededException(): BudgetExceededException
    {
        return new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_COST, 12.0, 10.0),
        );
    }

    private function createStructuredValidationFailure(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Structured completion did not match the required schema after one repair attempt.',
            1784500002,
        );
    }

    // ==================== resolveConfiguration ====================

    #[Test]
    public function testResolveConfigurationReturnsConfigByUid(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(42)
            ->willReturn($config);

        $result = $this->subject->resolveConfiguration(42);

        self::assertSame($config, $result);
    }

    #[Test]
    public function testResolveConfigurationFallsBackToDefaultWhenUidNotFound(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->expects(self::once())->method('findByUid')
            ->with(999)
            ->willReturn(null);
        $this->stubDefaultConfig($defaultConfig);

        $result = $this->subject->resolveConfiguration(999);

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testResolveConfigurationFallsBackToDefaultWhenUidIsNull(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();
        $this->stubDefaultConfig($defaultConfig);

        $result = $this->subject->resolveConfiguration();

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testResolveConfigurationFallsBackToDefaultWhenUidIsZero(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();
        $this->stubDefaultConfig($defaultConfig);

        // A uid of 0 must NOT trigger a repository lookup: the guard is `> 0`, not `>= 0`,
        // and it is an AND with the null check. Both mutations (`>= 0`, `||`) would let 0
        // through to findByUid().
        $this->configurationRepository
            ->expects(self::never())
            ->method('findByUid');

        $result = $this->subject->resolveConfiguration(0);

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testResolveConfigurationReturnsNullWhenNoConfigAvailable(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->resolveConfiguration();

        self::assertNull($result);
    }

    // ==================== generateConfiguration ====================

    #[Test]
    public function testGenerateConfigurationHappyPathReturnsLlmResult(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->willReturn([
                'identifier' => 'blog-summarizer',
                'name' => 'Blog Summarizer',
                'description' => 'Summarizes blog posts into concise paragraphs.',
                'system_prompt' => 'You are an expert content summarizer.',
                'temperature' => 0.3,
                'max_tokens' => 2048,
                'top_p' => 0.9,
                'frequency_penalty' => 0.1,
                'presence_penalty' => 0.0,
                'recommended_model' => 'gpt-5.2',
            ]);

        $result = $this->subject->generateConfiguration('summarize blog posts', $config);

        self::assertTrue($result['generated']);
        self::assertSame('blog-summarizer', $result['identifier']);
        self::assertSame('Blog Summarizer', $result['name']);
        self::assertSame('You are an expert content summarizer.', $result['system_prompt']);
        self::assertSame(0.3, $result['temperature']);
        self::assertSame(2048, $result['max_tokens']);
        self::assertSame(0.9, $result['top_p']);
        self::assertSame('gpt-5.2', $result['recommended_model']);
    }

    #[Test]
    public function testGenerateConfigurationFallbackWhenNoConfig(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateConfiguration('summarize articles');

        self::assertFalse($result['generated']);
        self::assertSame('New Configuration', $result['name']);
        self::assertSame('summarize articles', $result['description']);
        self::assertIsString($result['system_prompt']);
        self::assertStringContainsString('summarize articles', $result['system_prompt']);
        self::assertSame(0.7, $result['temperature']);
        self::assertSame(4096, $result['max_tokens']);
    }

    #[Test]
    public function testGenerateConfigurationFallbackOnLlmException(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('LLM service unavailable'));

        $result = $this->subject->generateConfiguration('translate content', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Configuration', $result['name']);
        self::assertSame('translate content', $result['description']);
    }

    #[Test]
    public function testGenerateConfigurationFallbackOnStructuredValidationFailure(): void
    {
        // A response that still fails the schema after the repair round-trip
        // surfaces as InvalidArgumentException from the completion service —
        // the wizard degrades to the fallback exactly like any other failure.
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createStructuredValidationFailure());

        $result = $this->subject->generateConfiguration('write poetry', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Configuration', $result['name']);
    }

    #[Test]
    public function testGenerateConfigurationRethrowsBudgetExceededException(): void
    {
        // A budget denial is an answer, not a generation failure — it must NOT
        // be disguised as the generic fallback.
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createBudgetExceededException());

        $this->expectException(BudgetExceededException::class);

        $this->subject->generateConfiguration('budget config', $config);
    }

    #[Test]
    public function testGenerateConfigurationHandlesCamelCaseKeys(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'camel-test',
            'name' => 'Camel Test',
            'description' => 'Test camelCase keys',
            'systemPrompt' => 'System prompt via camelCase.',
            'temperature' => 0.6,
            'maxTokens' => 8192,
            'topP' => 0.95,
            'frequencyPenalty' => 0.2,
            'presencePenalty' => 0.1,
            'recommendedModel' => 'gpt-5.2',
        ]);

        $result = $this->subject->generateConfiguration('test camel case', $config);

        self::assertTrue($result['generated']);
        self::assertSame('System prompt via camelCase.', $result['system_prompt']);
        self::assertSame(8192, $result['max_tokens']);
        self::assertSame(0.95, $result['top_p']);
        self::assertSame(0.2, $result['frequency_penalty']);
        self::assertSame(0.1, $result['presence_penalty']);
        self::assertSame('gpt-5.2', $result['recommended_model']);
    }

    #[Test]
    public function testGenerateConfigurationClampsTemperature(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'clamp-test',
            'name' => 'Clamp Test',
            'description' => 'Test clamping',
            'system_prompt' => 'Test.',
            'temperature' => 5.0,
            'max_tokens' => 999999,
            'top_p' => 3.0,
            'frequency_penalty' => -5.0,
            'presence_penalty' => 10.0,
        ]);

        $result = $this->subject->generateConfiguration('test clamping', $config);

        self::assertTrue($result['generated']);
        self::assertSame(2.0, $result['temperature']);
        self::assertSame(128000, $result['max_tokens']);
        self::assertSame(1.0, $result['top_p']);
        self::assertSame(-2.0, $result['frequency_penalty']);
        self::assertSame(2.0, $result['presence_penalty']);
    }

    #[Test]
    public function testGenerateConfigurationEmptyDescriptionProducesFallbackIdentifier(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateConfiguration('');

        self::assertFalse($result['generated']);
        self::assertSame('new-config', $result['identifier']);
    }

    #[Test]
    public function testGenerateConfigurationSanitizesIdentifier(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'Test_Config With  Spaces!!!',
            'name' => 'Test Config',
            'description' => 'Test',
            'system_prompt' => 'Test.',
        ]);

        $result = $this->subject->generateConfiguration('test sanitize', $config);

        self::assertSame('test-config-with-spaces', $result['identifier']);
    }

    #[Test]
    public function testGenerateConfigurationUsesDefaultConfigWhenNoneProvided(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();
        $this->stubDefaultConfig($defaultConfig);
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->with(self::anything(), $defaultConfig, self::anything(), self::anything())
            ->willReturn([
                'identifier' => 'auto-config',
                'name' => 'Auto Config',
                'description' => 'Uses default config.',
                'system_prompt' => 'Test.',
            ]);

        $result = $this->subject->generateConfiguration('auto config');

        self::assertTrue($result['generated']);
    }

    // ==================== generateTask ====================

    #[Test]
    public function testGenerateTaskHappyPathReturnsLlmResult(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->willReturn([
                'identifier' => 'summarize-article',
                'name' => 'Summarize Article',
                'description' => 'Summarizes articles into key points.',
                'category' => 'content',
                'prompt_template' => 'Summarize the following article:\n\n{{input}}',
                'output_format' => 'markdown',
            ]);

        $result = $this->subject->generateTask('summarize articles', $config);

        self::assertTrue($result['generated']);
        self::assertSame('summarize-article', $result['identifier']);
        self::assertSame('Summarize Article', $result['name']);
        self::assertSame('content', $result['category']);
        self::assertSame('markdown', $result['output_format']);
    }

    #[Test]
    public function testGenerateTaskFallbackWhenNoConfig(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateTask('analyze logs');

        self::assertFalse($result['generated']);
        self::assertSame('New Task', $result['name']);
        self::assertSame('analyze logs', $result['description']);
        self::assertSame('general', $result['category']);
        self::assertSame('markdown', $result['output_format']);
        self::assertIsString($result['prompt_template']);
        self::assertStringContainsString('{{input}}', $result['prompt_template']);
    }

    #[Test]
    public function testGenerateTaskFallbackOnLlmException(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $result = $this->subject->generateTask('debug code', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Task', $result['name']);
    }

    #[Test]
    public function testGenerateTaskFallbackOnStructuredValidationFailure(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createStructuredValidationFailure());

        $result = $this->subject->generateTask('some task', $config);

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskRethrowsBudgetExceededException(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createBudgetExceededException());

        $this->expectException(BudgetExceededException::class);

        $this->subject->generateTask('budget task', $config);
    }

    #[Test]
    public function testGenerateTaskValidatesCategory(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'invalid-cat',
            'name' => 'Invalid Category',
            'description' => 'Test invalid category.',
            'category' => 'nonexistent_category',
            'prompt_template' => '{{input}}',
            'output_format' => 'markdown',
        ]);

        $result = $this->subject->generateTask('test categories', $config);

        self::assertTrue($result['generated']);
        self::assertSame('general', $result['category']);
    }

    #[Test]
    public function testGenerateTaskValidatesOutputFormat(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'invalid-format',
            'name' => 'Invalid Format',
            'description' => 'Test invalid format.',
            'category' => 'content',
            'prompt_template' => '{{input}}',
            'output_format' => 'xml',
        ]);

        $result = $this->subject->generateTask('test format', $config);

        self::assertTrue($result['generated']);
        self::assertSame('markdown', $result['output_format']);
    }

    #[Test]
    public function testGenerateTaskHandlesCamelCaseKeys(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'camel-task',
            'name' => 'Camel Task',
            'description' => 'Test camelCase.',
            'category' => 'developer',
            'promptTemplate' => 'Do this: {{input}}',
            'outputFormat' => 'json',
        ]);

        $result = $this->subject->generateTask('camel case task', $config);

        self::assertTrue($result['generated']);
        self::assertSame('Do this: {{input}}', $result['prompt_template']);
        self::assertSame('json', $result['output_format']);
    }

    #[Test]
    public function testGenerateTaskEmptyDescriptionProducesFallbackIdentifier(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateTask('');

        self::assertFalse($result['generated']);
        self::assertSame('new-task', $result['identifier']);
    }

    // ==================== generateTaskWithChain ====================

    #[Test]
    public function testGenerateTaskWithChainHappyPath(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->willReturn([
                'task' => [
                    'identifier' => 'translate-text',
                    'name' => 'Translate Text',
                    'description' => 'Translates text between languages.',
                    'category' => 'content',
                    'prompt_template' => 'Translate the following:\n\n{{input}}',
                    'output_format' => 'plain',
                ],
                'configuration' => [
                    'identifier' => 'translator-config',
                    'name' => 'Translation Configuration',
                    'description' => 'Optimized for translation tasks.',
                    'system_prompt' => 'You are a professional translator.',
                    'temperature' => 0.2,
                    'max_tokens' => 8192,
                    'top_p' => 1.0,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                ],
                'recommended_model_id' => 'gpt-5.2',
                'suggested_model' => [
                    'name' => 'GPT-5.2',
                    'model_id' => 'gpt-5.2',
                    'description' => 'Best for translation tasks.',
                    'capabilities' => 'chat,streaming',
                ],
            ]);

        $result = $this->subject->generateTaskWithChain('translate content', $config);

        self::assertTrue($result['generated']);

        /** @var array<string, mixed> $task */
        $task = $result['task'];
        /** @var array<string, mixed> $configuration */
        $configuration = $result['configuration'];
        /** @var array<string, mixed> $suggestedModel */
        $suggestedModel = $result['suggested_model'];

        self::assertSame('translate-text', $task['identifier']);
        self::assertSame('Translate Text', $task['name']);
        self::assertSame('content', $task['category']);
        self::assertSame('translator-config', $configuration['identifier']);
        self::assertSame('You are a professional translator.', $configuration['system_prompt']);
        self::assertSame(0.2, $configuration['temperature']);
        self::assertSame('gpt-5.2', $result['recommended_model_id']);
        self::assertSame('GPT-5.2', $suggestedModel['name']);
        self::assertSame('chat,streaming', $suggestedModel['capabilities']);
    }

    #[Test]
    public function testGenerateTaskWithChainFallbackWhenNoConfig(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateTaskWithChain('generate reports');

        self::assertFalse($result['generated']);

        /** @var array<string, mixed> $task */
        $task = $result['task'];
        /** @var array<string, mixed> $configuration */
        $configuration = $result['configuration'];
        /** @var array<string, mixed> $suggestedModel */
        $suggestedModel = $result['suggested_model'];

        self::assertSame('New Task', $task['name']);
        self::assertSame('general', $task['category']);
        self::assertIsString($task['prompt_template']);
        self::assertStringContainsString('{{input}}', $task['prompt_template']);
        self::assertSame('New Task Configuration', $configuration['name']);
        self::assertSame(0.7, $configuration['temperature']);
        self::assertSame('', $result['recommended_model_id']);
        self::assertSame('chat', $suggestedModel['capabilities']);
    }

    #[Test]
    public function testGenerateTaskWithChainFallbackOnLlmException(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('Service down'));

        $result = $this->subject->generateTaskWithChain('create tasks', $config);

        self::assertFalse($result['generated']);
        /** @var array<string, mixed> $task */
        $task = $result['task'];
        self::assertSame('New Task', $task['name']);
    }

    #[Test]
    public function testGenerateTaskWithChainFallbackOnStructuredValidationFailure(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createStructuredValidationFailure());

        $result = $this->subject->generateTaskWithChain('something', $config);

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskWithChainRethrowsBudgetExceededException(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException($this->createBudgetExceededException());

        $this->expectException(BudgetExceededException::class);

        $this->subject->generateTaskWithChain('budget chain', $config);
    }

    #[Test]
    public function testGenerateTaskWithChainHandlesFlatStructure(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        // When the LLM returns flat keys instead of nested task/configuration objects,
        // normalizeFullChainResult treats the whole data as task data
        $this->stubStructuredResult([
            'identifier' => 'flat-task',
            'name' => 'Flat Task',
            'description' => 'Flat structure response.',
            'category' => 'developer',
            'prompt_template' => '{{input}}',
            'output_format' => 'plain',
        ]);

        $result = $this->subject->generateTaskWithChain('flat structure test', $config);

        self::assertTrue($result['generated']);
        /** @var array<string, mixed> $task */
        $task = $result['task'];
        self::assertSame('flat-task', $task['identifier']);
        self::assertSame('Flat Task', $task['name']);
    }

    #[Test]
    public function testGenerateTaskWithChainEmptyDescriptionProducesFallbackIdentifier(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateTaskWithChain('');

        self::assertFalse($result['generated']);
        /** @var array<string, mixed> $task */
        $task = $result['task'];
        /** @var array<string, mixed> $configuration */
        $configuration = $result['configuration'];
        self::assertSame('new-task', $task['identifier']);
        self::assertSame('new-task-config', $configuration['identifier']);
    }

    #[Test]
    public function testGenerateTaskWithChainClampsConfigValues(): void
    {
        $result = $this->generateTaskWithChainFromPayload([
            'task' => [
                'identifier' => 'clamp-chain',
                'name' => 'Clamp Chain',
                'description' => 'Test clamping in chain.',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => 'clamp-config',
                'name' => 'Clamp Config',
                'description' => 'Testing clamp.',
                'system_prompt' => 'Test.',
                'temperature' => -1.0,
                'max_tokens' => 0,
                'top_p' => -0.5,
                'frequency_penalty' => 5.0,
                'presence_penalty' => -3.0,
            ],
            'recommended_model_id' => 'test',
            'suggested_model' => [
                'name' => 'Test',
                'model_id' => 'test',
                'description' => 'Test',
                'capabilities' => 'chat',
            ],
        ]);

        self::assertTrue($result['generated']);
        /** @var array<string, mixed> $configuration */
        $configuration = $result['configuration'];
        self::assertSame(0.0, $configuration['temperature']);
        self::assertSame(1, $configuration['max_tokens']);
        self::assertSame(0.0, $configuration['top_p']);
        self::assertSame(2.0, $configuration['frequency_penalty']);
        self::assertSame(-2.0, $configuration['presence_penalty']);
    }

    // ==================== findBestExistingModel ====================

    #[Test]
    public function testFindBestExistingModelExactMatch(): void
    {
        $model = $this->createActiveModel('gpt-5.2', 'GPT-5.2');
        $otherModel = $this->createActiveModel('claude-opus-4-5', 'Claude Opus 4.5');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$otherModel, $model]));

        $result = $this->subject->findBestExistingModel('gpt-5.2');

        self::assertNotNull($result);
        self::assertSame('gpt-5.2', $result->getModelId());
    }

    #[Test]
    public function testFindBestExistingModelPartialMatchRecommendedContainsModel(): void
    {
        $model = $this->createActiveModel('gpt-4', 'GPT-4');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$model]));

        // "gpt-4-turbo" contains "gpt-4"
        $result = $this->subject->findBestExistingModel('gpt-4-turbo');

        self::assertNotNull($result);
        self::assertSame('gpt-4', $result->getModelId());
    }

    #[Test]
    public function testFindBestExistingModelPartialMatchModelContainsRecommended(): void
    {
        $model = $this->createActiveModel('gpt-4-turbo-preview', 'GPT-4 Turbo Preview');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$model]));

        // "gpt-4-turbo-preview" contains "gpt-4"
        $result = $this->subject->findBestExistingModel('gpt-4');

        self::assertNotNull($result);
        self::assertSame('gpt-4-turbo-preview', $result->getModelId());
    }

    #[Test]
    public function testFindBestExistingModelNoMatch(): void
    {
        $model = $this->createActiveModel('claude-opus-4-5', 'Claude Opus 4.5');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$model]));

        $result = $this->subject->findBestExistingModel('llama-3.3-70b');

        self::assertNull($result);
    }

    #[Test]
    public function testFindBestExistingModelEmptyString(): void
    {
        $result = $this->subject->findBestExistingModel('');

        self::assertNull($result);
    }

    #[Test]
    public function testFindBestExistingModelNoModelsAvailable(): void
    {
        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        $result = $this->subject->findBestExistingModel('gpt-5.2');

        self::assertNull($result);
    }

    #[Test]
    public function testFindBestExistingModelPrefersExactOverPartial(): void
    {
        $exactModel = $this->createActiveModel('gpt-4', 'GPT-4');
        $partialModel = $this->createActiveModel('gpt-4-turbo', 'GPT-4 Turbo');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$partialModel, $exactModel]));

        $result = $this->subject->findBestExistingModel('gpt-4');

        self::assertNotNull($result);
        // Exact match should be found first (separate loop)
        self::assertSame('gpt-4', $result->getModelId());
    }

    // ==================== findBestExistingConfiguration ====================

    #[Test]
    public function testFindBestExistingConfigurationFound(): void
    {
        $config = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$config]));

        $result = $this->subject->findBestExistingConfiguration('any description');

        self::assertNotNull($result);
        self::assertSame($config, $result);
    }

    #[Test]
    public function testFindBestExistingConfigurationSkipsConfigWithoutModel(): void
    {
        $configNoModel = new LlmConfiguration();
        $configNoModel->_setProperty('systemPrompt', 'I have a prompt');
        // No model set

        $configWithModel = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$configNoModel, $configWithModel]));

        $result = $this->subject->findBestExistingConfiguration('needs model');

        self::assertSame($configWithModel, $result);
    }

    #[Test]
    public function testFindBestExistingConfigurationSkipsConfigWithoutSystemPrompt(): void
    {
        $model = new Model();
        $model->setModelId('gpt-5.2');

        $configNoPrompt = new LlmConfiguration();
        $configNoPrompt->_setProperty('llmModel', $model);
        $configNoPrompt->_setProperty('systemPrompt', '');

        $configWithPrompt = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$configNoPrompt, $configWithPrompt]));

        $result = $this->subject->findBestExistingConfiguration('needs prompt');

        self::assertSame($configWithPrompt, $result);
    }

    #[Test]
    public function testFindBestExistingConfigurationFallsBackToDefault(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));
        $this->stubDefaultConfig($defaultConfig);

        $result = $this->subject->findBestExistingConfiguration('nothing active');

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testFindBestExistingConfigurationReturnsNullWhenNothingAvailable(): void
    {
        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));
        $this->stubNoDefaultConfig();

        $result = $this->subject->findBestExistingConfiguration('nothing');

        self::assertNull($result);
    }

    // ==================== Type conversion via normalization (str, toFloat, toInt) ====================

    #[Test]
    public function testNormalizationHandlesNumericStringsInTemperature(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'numeric-test',
            'name' => 'Numeric Test',
            'description' => 'Test numeric string handling.',
            'system_prompt' => 'Test.',
            'temperature' => '0.5',
            'max_tokens' => '2048',
            'top_p' => '0.9',
            'frequency_penalty' => '0.1',
            'presence_penalty' => '0.2',
        ]);

        $result = $this->subject->generateConfiguration('numeric strings', $config);

        self::assertTrue($result['generated']);
        self::assertSame(0.5, $result['temperature']);
        self::assertSame(2048, $result['max_tokens']);
        self::assertSame(0.9, $result['top_p']);
    }

    #[Test]
    public function testNormalizationHandlesNonNumericValues(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'non-numeric',
            'name' => 123,
            'description' => true,
            'system_prompt' => null,
            'temperature' => 'not-a-number',
            'max_tokens' => 'high',
            'top_p' => [],
        ]);

        $result = $this->subject->generateConfiguration('non-numeric test', $config);

        self::assertTrue($result['generated']);
        // str() handles numeric values
        self::assertSame('123', $result['name']);
        // toStr() returns '' for bool, which falls back to input description
        self::assertSame('non-numeric test', $result['description']);
        self::assertSame('', $result['system_prompt']);
        // toFloat() returns 0.0 for non-numeric, clamped to 0.0
        self::assertSame(0.0, $result['temperature']);
        // toInt() returns 0 for non-numeric, clamped to min 1
        self::assertSame(1, $result['max_tokens']);
        // toFloat() returns 0.0 for array, clamped to 0.0
        self::assertSame(0.0, $result['top_p']);
    }

    #[Test]
    public function testNormalizationHandlesMissingFields(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        // Minimal payload — most fields missing
        $this->stubStructuredResult([
            'identifier' => 'minimal',
        ]);

        $result = $this->subject->generateConfiguration('minimal test', $config);

        self::assertTrue($result['generated']);
        self::assertSame('minimal', $result['identifier']);
        self::assertSame('New Configuration', $result['name']);
        self::assertSame('minimal test', $result['description']);
        self::assertSame('', $result['system_prompt']);
        self::assertSame(0.7, $result['temperature']);
        self::assertSame(4096, $result['max_tokens']);
        self::assertSame(1.0, $result['top_p']);
        self::assertSame(0.0, $result['frequency_penalty']);
        self::assertSame(0.0, $result['presence_penalty']);
        self::assertSame('', $result['recommended_model']);
    }

    // ==================== getDefaultConfiguration edge cases ====================

    #[Test]
    public function testGenerateConfigurationUsesFirstActiveConfigWhenNoDefault(): void
    {
        $activeConfig = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findDefault')
            ->willReturn(null);
        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$activeConfig]);
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->with(self::anything(), $activeConfig, self::anything(), self::anything())
            ->willReturn([
                'identifier' => 'fallback-active',
                'name' => 'Fallback Active',
                'description' => 'Uses first active config.',
                'system_prompt' => 'Test.',
            ]);

        $result = $this->subject->generateConfiguration('test fallback active');

        self::assertTrue($result['generated']);
    }

    #[Test]
    public function testGenerateConfigurationSkipsDefaultWithoutModel(): void
    {
        $defaultNoModel = new LlmConfiguration();
        $defaultNoModel->_setProperty('isDefault', true);
        $defaultNoModel->_setProperty('isActive', true);
        // No model assigned

        $activeWithModel = $this->createConfigurationWithModel();

        $this->configurationRepository
            ->method('findDefault')
            ->willReturn($defaultNoModel);
        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$activeWithModel]);
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));

        $this->completionService
            ->expects(self::once())
            ->method('completeStructuredForConfiguration')
            ->willReturn([
                'identifier' => 'skip-default',
                'name' => 'Skip Default',
                'description' => 'Skipped default without model.',
                'system_prompt' => 'Test.',
            ]);

        $result = $this->subject->generateConfiguration('test skip default');

        self::assertTrue($result['generated']);
    }

    // ==================== Identifier sanitization ====================

    #[Test]
    public function testFallbackConfigurationSanitizesDescriptionAsIdentifier(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateConfiguration('My Cool Feature!!!');

        self::assertFalse($result['generated']);
        self::assertSame('my-cool-feature', $result['identifier']);
    }

    #[Test]
    public function testFallbackTaskSanitizesDescriptionAsIdentifier(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->generateTask('Analyze_Server   Logs!!!');

        self::assertFalse($result['generated']);
        self::assertSame('analyze-server-logs', $result['identifier']);
    }

    // ==================== Valid category/format values ====================

    #[Test]
    public function testGenerateTaskAcceptsAllValidCategories(): void
    {
        $validCategories = ['content', 'log_analysis', 'system', 'developer', 'general'];

        foreach ($validCategories as $category) {
            $config = $this->createConfigurationWithModel();
            $this->configurationRepository->method('findAll')->willReturn([]);

            // Reset mock for each iteration
            $completionService = $this->createMock(CompletionServiceInterface::class);
            $completionService
                ->method('completeStructuredForConfiguration')
                ->willReturn([
                    'identifier' => 'cat-' . $category,
                    'name' => 'Category Test',
                    'description' => 'Test.',
                    'category' => $category,
                    'prompt_template' => '{{input}}',
                    'output_format' => 'markdown',
                ]);

            $subject = new WizardGeneratorService(
                $completionService,
                $this->configurationRepository,
                $this->modelRepository,
                $this->createLoggerMock(),
            );

            $result = $subject->generateTask('test ' . $category, $config);

            self::assertSame($category, $result['category'], "Category '{$category}' should be accepted");
        }
    }

    #[Test]
    public function testGenerateTaskAcceptsAllValidOutputFormats(): void
    {
        $validFormats = ['markdown', 'json', 'plain', 'html'];

        foreach ($validFormats as $format) {
            $config = $this->createConfigurationWithModel();
            $this->configurationRepository->method('findAll')->willReturn([]);

            $completionService = $this->createMock(CompletionServiceInterface::class);
            $completionService
                ->method('completeStructuredForConfiguration')
                ->willReturn([
                    'identifier' => 'fmt-' . $format,
                    'name' => 'Format Test',
                    'description' => 'Test.',
                    'category' => 'general',
                    'prompt_template' => '{{input}}',
                    'output_format' => $format,
                ]);

            $subject = new WizardGeneratorService(
                $completionService,
                $this->configurationRepository,
                $this->modelRepository,
                $this->createLoggerMock(),
            );

            $result = $subject->generateTask('test ' . $format, $config);

            self::assertSame($format, $result['output_format'], "Format '{$format}' should be accepted");
        }
    }

    // ==================== buildConfigurationContext with models and configs ====================

    #[Test]
    public function testGenerateConfigurationIncludesModelsInPrompt(): void
    {
        // When models are present, buildConfigurationContext includes them in the prompt
        // sent to the LLM. We verify this indirectly: the LLM is called and receives
        // a non-empty prompt (we just verify the flow succeeds with models available).
        $config = $this->createConfigurationWithModel();

        $model = $this->createActiveModel('gpt-5.2', 'GPT-5.2');
        $model->setDescription('Flagship model');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$model]));

        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'test-config',
            'name' => 'Test',
            'description' => 'A config',
            'system_prompt' => 'You are helpful.',
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => 'gpt-5.2',
        ]);

        $result = $this->subject->generateConfiguration('test config', $config);

        self::assertTrue($result['generated']);
        self::assertSame('test-config', $result['identifier']);
    }

    #[Test]
    public function testGenerateConfigurationIncludesExistingConfigsInContext(): void
    {
        // When configs exist, buildConfigurationContext includes them in the prompt
        $config = $this->createConfigurationWithModel();

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('My Existing Config');
        $existingConfig->setDescription('Does something useful');

        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$existingConfig]);

        $this->stubStructuredResult([
            'identifier' => 'new-config',
            'name' => 'New Config',
            'description' => 'Avoids duplicate',
            'system_prompt' => 'Be helpful.',
            'temperature' => 0.5,
            'max_tokens' => 2048,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => '',
        ]);

        $result = $this->subject->generateConfiguration('something new', $config);

        self::assertTrue($result['generated']);
        self::assertSame('new-config', $result['identifier']);
    }

    #[Test]
    public function testGenerateConfigurationContextSkipsNonLlmConfigurationItems(): void
    {
        // findAll() may return objects that are not LlmConfiguration instances
        $config = $this->createConfigurationWithModel();

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        // Mix valid and non-LlmConfiguration items
        $validConfig = new LlmConfiguration();
        $validConfig->setName('Valid Config');
        $validConfig->setDescription('A real config');

        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$validConfig, new stdClass()]);

        $this->stubStructuredResult([
            'identifier' => 'gen-config',
            'name' => 'Generated',
            'description' => 'Works fine',
            'system_prompt' => 'Help.',
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => '',
        ]);

        $result = $this->subject->generateConfiguration('test', $config);

        self::assertTrue($result['generated']);
    }

    // ==================== buildTaskContext with existing configs ====================

    #[Test]
    public function testGenerateTaskIncludesExistingConfigsInContext(): void
    {
        // When configs exist, buildTaskContext includes them in the prompt
        $config = $this->createConfigurationWithModel();

        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('Content Assistant');
        $existingConfig->setIdentifier('content-assistant');

        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$existingConfig]);

        $this->stubStructuredResult([
            'identifier' => 'summarize-text',
            'name' => 'Summarize Text',
            'description' => 'Summarizes text content',
            'category' => 'content',
            'prompt_template' => 'Summarize: {{input}}',
            'output_format' => 'markdown',
        ]);

        $result = $this->subject->generateTask('summarize articles', $config);

        self::assertTrue($result['generated']);
        self::assertSame('summarize-text', $result['identifier']);
    }

    #[Test]
    public function testGenerateTaskContextSkipsNonLlmConfigurationItems(): void
    {
        $config = $this->createConfigurationWithModel();

        $validConfig = new LlmConfiguration();
        $validConfig->setName('Valid');
        $validConfig->setIdentifier('valid');

        // Non-LlmConfiguration item should be skipped
        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$validConfig, new stdClass()]);

        $this->stubStructuredResult([
            'identifier' => 'task-x',
            'name' => 'Task X',
            'description' => 'Does X',
            'category' => 'general',
            'prompt_template' => '{{input}}',
            'output_format' => 'plain',
        ]);

        $result = $this->subject->generateTask('task x', $config);

        self::assertTrue($result['generated']);
    }

    // ==================== buildFullChainContext with models and configs ====================

    #[Test]
    public function testGenerateTaskWithChainIncludesModelsAndConfigsInContext(): void
    {
        $config = $this->createConfigurationWithModel();

        $model = $this->createActiveModel('gpt-5.2', 'GPT-5.2');
        $model->setDescription('Flagship model');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$model]));

        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('My Config');
        $existingConfig->setIdentifier('my-config');
        $existingConfig->setDescription('An existing configuration');

        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$existingConfig]);

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        $this->stubStructuredResult([
            'task' => [
                'identifier' => 'chain-task',
                'name' => 'Chain Task',
                'description' => 'Task in a chain',
                'category' => 'content',
                'prompt_template' => 'Process: {{input}}',
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => 'chain-config',
                'name' => 'Chain Config',
                'description' => 'Config for the chain',
                'system_prompt' => 'Be helpful.',
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'recommended_model_id' => 'gpt-5.2',
            'suggested_model' => [
                'name' => 'GPT-5.2',
                'model_id' => 'gpt-5.2',
                'description' => 'Great model',
                'capabilities' => 'chat,vision',
            ],
        ]);

        $result = $this->subject->generateTaskWithChain('chain task description', $config);

        self::assertTrue($result['generated']);
        self::assertIsArray($result['task']);
        /** @var array<string, mixed> $task */
        $task = $result['task'];
        self::assertSame('chain-task', $task['identifier']);
        self::assertSame('gpt-5.2', $result['recommended_model_id']);
    }

    #[Test]
    public function testGenerateTaskWithChainFullChainContextSkipsNonLlmConfigItems(): void
    {
        $config = $this->createConfigurationWithModel();

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        $validConfig = new LlmConfiguration();
        $validConfig->setName('Valid Config');
        $validConfig->setIdentifier('valid-config');
        $validConfig->setDescription('A valid config');

        $this->configurationRepository
            ->method('findAll')
            ->willReturn([$validConfig, new stdClass()]);

        $this->configurationRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([]));

        $this->stubStructuredResult([
            'task' => [
                'identifier' => 'skip-test',
                'name' => 'Skip Test',
                'description' => 'Tests skipping',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => 'plain',
            ],
            'configuration' => [
                'identifier' => 'skip-config',
                'name' => 'Skip Config',
                'description' => 'Config',
                'system_prompt' => 'Help.',
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'recommended_model_id' => '',
            'suggested_model' => [
                'name' => '',
                'model_id' => '',
                'description' => '',
                'capabilities' => 'chat',
            ],
        ]);

        $result = $this->subject->generateTaskWithChain('skip test', $config);

        self::assertTrue($result['generated']);
    }

    // ==================== buildFullChainContext models list is sliced to max 10 ====================

    #[Test]
    public function testGenerateConfigurationContextLimitsModelsToTen(): void
    {
        $config = $this->createConfigurationWithModel();

        // Create 12 active models — context should only include first 10
        $models = [];
        for ($i = 1; $i <= 12; $i++) {
            $m = $this->createActiveModel('model-' . $i, 'Model ' . $i);
            $m->setDescription('Description ' . $i);
            $models[] = $m;
        }

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub($models));

        $this->configurationRepository->method('findAll')->willReturn([]);

        // The LLM still gets called — the test verifies no error occurs
        $this->stubStructuredResult([
            'identifier' => 'limited',
            'name' => 'Limited',
            'description' => 'Test',
            'system_prompt' => 'Help.',
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => 'model-1',
        ]);

        $result = $this->subject->generateConfiguration('limit test', $config);

        self::assertTrue($result['generated']);
    }

    // ==================== Outgoing request payload assertions ====================

    private function assertOccursBefore(string $haystack, string $first, string $second): void
    {
        $posFirst = strpos($haystack, $first);
        $posSecond = strpos($haystack, $second);
        self::assertIsInt($posFirst, "'{$first}' not found in prompt");
        self::assertIsInt($posSecond, "'{$second}' not found in prompt");
        self::assertLessThan($posSecond, $posFirst, "'{$first}' should occur before '{$second}'");
    }

    /**
     * Assert the captured options carry a system prompt containing $needle,
     * returning nothing — the prompt itself is asserted by the caller.
     *
     * @param array{prompt: string, configuration: LlmConfiguration, schema: array<string, mixed>, options: ChatOptions|null}|null $captured
     */
    private function assertSystemPromptContains(?array $captured, string $needle): void
    {
        self::assertNotNull($captured);
        self::assertInstanceOf(ChatOptions::class, $captured['options']);
        $systemPrompt = $captured['options']->getSystemPrompt();
        self::assertIsString($systemPrompt);
        self::assertStringContainsString($needle, $systemPrompt);
    }

    #[Test]
    public function testGenerateConfigurationSendsUserRequestPromptAndSystemPromptOption(): void
    {
        $config = $this->createConfigurationWithModel();

        // 12 active models so the array_slice(…, 0, 10) window is observable.
        $models = [];
        for ($i = 1; $i <= 12; ++$i) {
            $m = $this->createActiveModel('model-' . $i, 'Model ' . $i);
            $m->setDescription('Description ' . $i);
            $models[] = $m;
        }

        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub($models));

        // Non-LlmConfiguration item FIRST: with `continue` the valid one is still collected;
        // a `break` mutation would drop it.
        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('Existing One');
        $existingConfig->setDescription('Avoid me');
        $this->configurationRepository->method('findAll')->willReturn([new stdClass(), $existingConfig]);

        $captured = null;
        $this->stubStructuredCapturing([
            'identifier' => 'payload-config',
            'name' => 'Payload Config',
            'description' => 'Payload check.',
            'system_prompt' => 'Be helpful.',
        ], $captured);

        $result = $this->subject->generateConfiguration('generate config capture', $config);

        self::assertTrue($result['generated']);

        // System prompt travels as a ChatOptions option, not as a message.
        $this->assertSystemPromptContains($captured, 'You are an expert at configuring LLM integrations');

        // Prompt: "User request:" prefix + full built context.
        self::assertNotNull($captured);
        $prompt = $captured['prompt'];
        self::assertStringContainsString('User request: generate config capture', $prompt);

        // Models section: label present, slice window [0,10) — models 1-10 in, 11-12 out.
        self::assertStringContainsString('Available models:', $prompt);
        self::assertStringContainsString('(model-1)', $prompt);
        self::assertStringContainsString('(model-10)', $prompt);
        self::assertStringNotContainsString('(model-11)', $prompt);
        self::assertStringNotContainsString('(model-12)', $prompt);
        $this->assertOccursBefore($prompt, 'Available models:', '(model-1)');

        // Configs section: label present, collected config kept, correct concat order.
        self::assertStringContainsString('Existing configurations (avoid duplicates):', $prompt);
        self::assertStringContainsString('Existing One', $prompt);
        $this->assertOccursBefore($prompt, 'Existing configurations (avoid duplicates):', 'Existing One');
    }

    #[Test]
    public function testGenerateTaskSendsUserRequestPromptAndSystemPromptOption(): void
    {
        $config = $this->createConfigurationWithModel();

        // Non-LlmConfiguration item FIRST so a `break` mutation drops the valid config.
        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('Task Cfg');
        $existingConfig->setIdentifier('task-cfg');
        $this->configurationRepository->method('findAll')->willReturn([new stdClass(), $existingConfig]);

        $captured = null;
        $this->stubStructuredCapturing([
            'identifier' => 'payload-task',
            'name' => 'Payload Task',
            'description' => 'Payload check.',
            'category' => 'content',
            'prompt_template' => '{{input}}',
            'output_format' => 'markdown',
        ], $captured);

        $result = $this->subject->generateTask('generate task capture', $config);

        self::assertTrue($result['generated']);

        $this->assertSystemPromptContains($captured, 'You are an expert at creating LLM task templates');

        self::assertNotNull($captured);
        $prompt = $captured['prompt'];
        self::assertStringContainsString('User request: generate task capture', $prompt);
        self::assertStringContainsString('Task categories: content, log_analysis, system, developer, general', $prompt);
        self::assertStringContainsString('Output formats: markdown, json, plain, html', $prompt);
        self::assertStringContainsString('Available configurations:', $prompt);
        self::assertStringContainsString('Task Cfg (identifier: task-cfg)', $prompt);
        $this->assertOccursBefore($prompt, 'Available configurations:', 'Task Cfg (identifier: task-cfg)');
    }

    #[Test]
    public function testGenerateTaskWithChainSendsUserRequestPromptAndSystemPromptOption(): void
    {
        $config = $this->createConfigurationWithModel();

        $model = $this->createActiveModel('gpt-5.2', 'GPT-5.2');
        $model->setDescription('Flagship');
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([$model]));

        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('Chain Existing');
        $existingConfig->setIdentifier('chain-existing');
        $existingConfig->setDescription('Reference config');
        $this->configurationRepository->method('findAll')->willReturn([new stdClass(), $existingConfig]);

        $captured = null;
        $this->stubStructuredCapturing([
            'task' => [
                'identifier' => 'chain-payload',
                'name' => 'Chain Payload',
                'description' => 'Payload check.',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => 'chain-payload-config',
                'name' => 'Chain Payload Config',
                'description' => 'Config.',
                'system_prompt' => 'Help.',
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'recommended_model_id' => 'gpt-5.2',
            'suggested_model' => [
                'name' => 'GPT-5.2',
                'model_id' => 'gpt-5.2',
                'description' => 'Fit',
                'capabilities' => 'chat',
            ],
        ], $captured);

        $result = $this->subject->generateTaskWithChain('generate chain capture', $config);

        self::assertTrue($result['generated']);

        $this->assertSystemPromptContains($captured, 'You are an expert at creating complete LLM task setups');

        self::assertNotNull($captured);
        $prompt = $captured['prompt'];
        self::assertStringContainsString('User request: generate chain capture', $prompt);
        self::assertStringContainsString('Available models (prefer these):', $prompt);
        self::assertStringContainsString('(gpt-5.2)', $prompt);
        self::assertStringContainsString('Existing configurations (for reference, avoid duplicate names):', $prompt);
        self::assertStringContainsString('Chain Existing', $prompt);
    }

    // ==================== Schemas qualify for provider strict mode (ADR-126/128) ====================

    /**
     * Assert the schema root is a strict object schema: additionalProperties
     * is exactly false and `required` lists every `properties` key — the two
     * conditions OpenAI-style strict mode demands.
     *
     * @param array<string, mixed> $schema
     */
    private function assertStrictRootSchema(array $schema): void
    {
        self::assertSame('object', $schema['type'] ?? null);
        self::assertArrayHasKey('additionalProperties', $schema);
        self::assertFalse($schema['additionalProperties']);

        assert(isset($schema['properties'], $schema['required']));
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required']);
    }

    #[Test]
    public function testGenerateConfigurationPassesStrictModeQualifiedSchema(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $captured = null;
        $this->stubStructuredCapturing(['identifier' => 'schema-probe'], $captured);

        $this->subject->generateConfiguration('schema probe', $config);

        self::assertNotNull($captured);
        $this->assertStrictRootSchema($captured['schema']);
    }

    #[Test]
    public function testGenerateTaskPassesStrictModeQualifiedSchema(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $captured = null;
        $this->stubStructuredCapturing(['identifier' => 'schema-probe'], $captured);

        $this->subject->generateTask('schema probe', $config);

        self::assertNotNull($captured);
        $this->assertStrictRootSchema($captured['schema']);
    }

    #[Test]
    public function testGenerateTaskWithChainPassesStrictModeQualifiedSchema(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $captured = null;
        $this->stubStructuredCapturing(['identifier' => 'schema-probe'], $captured);

        $this->subject->generateTaskWithChain('schema probe', $config);

        self::assertNotNull($captured);
        $this->assertStrictRootSchema($captured['schema']);
    }

    // ==================== Exception path logs the cause ====================

    #[Test]
    public function testGenerateConfigurationLogsExceptionCauseOnFailure(): void
    {
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);
        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Wizard configuration generation failed; using fallback',
                self::callback(
                    static fn(array $context): bool => array_key_exists('exception', $context)
                        && $context['exception'] instanceof Throwable,
                ),
            );

        $subject = new WizardGeneratorService(
            $this->completionService,
            $this->configurationRepository,
            $this->modelRepository,
            $logger,
        );

        $result = $subject->generateConfiguration('log config', $this->createConfigurationWithModel());

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskLogsExceptionCauseOnFailure(): void
    {
        $this->configurationRepository->method('findAll')->willReturn([]);
        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Wizard task generation failed; using fallback',
                self::callback(
                    static fn(array $context): bool => array_key_exists('exception', $context)
                        && $context['exception'] instanceof Throwable,
                ),
            );

        $subject = new WizardGeneratorService(
            $this->completionService,
            $this->configurationRepository,
            $this->modelRepository,
            $logger,
        );

        $result = $subject->generateTask('log task', $this->createConfigurationWithModel());

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskWithChainLogsExceptionCauseOnFailure(): void
    {
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);
        $this->completionService
            ->method('completeStructuredForConfiguration')
            ->willThrowException(new RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Wizard task-chain generation failed; using fallback',
                self::callback(
                    static fn(array $context): bool => array_key_exists('exception', $context)
                        && $context['exception'] instanceof Throwable,
                ),
            );

        $subject = new WizardGeneratorService(
            $this->completionService,
            $this->configurationRepository,
            $this->modelRepository,
            $logger,
        );

        $result = $subject->generateTaskWithChain('log chain', $this->createConfigurationWithModel());

        self::assertFalse($result['generated']);
    }

    // ==================== findBestExistingModel loop stops at first match ====================

    #[Test]
    public function testFindBestExistingModelExactMatchStopsAtFirst(): void
    {
        $first = $this->createActiveModel('dup-model', 'First');
        $second = $this->createActiveModel('dup-model', 'Second');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$first, $second]));

        $result = $this->subject->findBestExistingModel('dup-model');

        // `break` returns the first exact match; a `continue` mutation would overwrite
        // it with the second.
        self::assertSame($first, $result);
    }

    #[Test]
    public function testFindBestExistingModelPartialMatchStopsAtFirst(): void
    {
        $first = $this->createActiveModel('gpt-4-alpha', 'Alpha');
        $second = $this->createActiveModel('gpt-4-beta', 'Beta');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResultStub([$first, $second]));

        // No exact match on 'gpt-4'; both partially match. `break` returns the first.
        $result = $this->subject->findBestExistingModel('gpt-4');

        self::assertSame($first, $result);
    }

    // ==================== getDefaultConfiguration requires instanceof AND active AND model ====================

    #[Test]
    public function testGenerateConfigurationSkipsInactiveConfigWithModel(): void
    {
        $model = new Model();
        $model->setModelId('gpt-5.2');

        $inactiveWithModel = new LlmConfiguration();
        $inactiveWithModel->_setProperty('llmModel', $model);
        $inactiveWithModel->_setProperty('isActive', false);
        $inactiveWithModel->_setProperty('isDefault', false);

        $this->configurationRepository->method('findDefault')->willReturn(null);
        $this->configurationRepository->method('findAll')->willReturn([$inactiveWithModel]);
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));

        // The only candidate is inactive: getDefaultConfiguration() must return null and the
        // wizard must fall back WITHOUT ever calling the LLM. Both `&&`→`||` mutations would
        // wrongly accept this config and reach completeStructuredForConfiguration().
        $this->completionService
            ->expects(self::never())
            ->method('completeStructuredForConfiguration');

        $result = $this->subject->generateConfiguration('inactive only');

        self::assertFalse($result['generated']);
    }

    // ==================== No-config guard returns immediately (ReturnRemoval) ====================

    /**
     * Build a subject whose logger is a mock that must never receive warning().
     *
     * Without the early `return $this->fallback…()` the code proceeds into the
     * LLM call with a null config, which always ends in a logged warning
     * (the stub completion mock returns an empty array only for a real config;
     * with null the call itself is a TypeError caught as Throwable).
     */
    private function createSubjectExpectingNoWarning(): WizardGeneratorService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        return new WizardGeneratorService(
            $this->completionService,
            $this->configurationRepository,
            $this->modelRepository,
            $logger,
        );
    }

    #[Test]
    public function testGenerateConfigurationWithoutConfigReturnsBeforeBuildingContext(): void
    {
        $this->stubNoDefaultConfig();
        $this->modelRepository->expects(self::never())->method('findActive');

        $result = $this->createSubjectExpectingNoWarning()->generateConfiguration('no config path');

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskWithoutConfigReturnsBeforeBuildingContext(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->createSubjectExpectingNoWarning()->generateTask('no config path');

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskWithChainWithoutConfigReturnsBeforeBuildingContext(): void
    {
        $this->stubNoDefaultConfig();
        $this->modelRepository->expects(self::never())->method('findActive');

        $result = $this->createSubjectExpectingNoWarning()->generateTaskWithChain('no config path');

        self::assertFalse($result['generated']);
    }

    // ==================== Normalization: snake_case wins over camelCase when both present ====================

    /**
     * Run generateConfiguration with a fixed structured-completion result.
     *
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    private function generateConfigurationFromResult(array $parsed): array
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult($parsed);

        return $this->subject->generateConfiguration('normalize probe request', $config);
    }

    #[Test]
    public function testNormalizeConfigurationPrefersSnakeCaseOverCamelCase(): void
    {
        $result = $this->generateConfigurationFromResult([
            'identifier' => 'prec-config',
            'name' => 'Precedence Config',
            'description' => 'Polished config description.',
            'system_prompt' => 'Snake sys.',
            'systemPrompt' => 'Camel sys.',
            'max_tokens' => 2048,
            'maxTokens' => 512,
            'top_p' => 0.25,
            'topP' => 0.75,
            'frequency_penalty' => 0.5,
            'frequencyPenalty' => 1.5,
            'presence_penalty' => 0.75,
            'presencePenalty' => 1.75,
            'recommended_model' => 'snake-model',
            'recommendedModel' => 'camel-model',
        ]);

        self::assertTrue($result['generated']);
        // The LLM's polished description wins over the raw user request.
        self::assertSame('Polished config description.', $result['description']);
        self::assertSame('Snake sys.', $result['system_prompt']);
        self::assertSame(2048, $result['max_tokens']);
        self::assertSame(0.25, $result['top_p']);
        self::assertSame(0.5, $result['frequency_penalty']);
        self::assertSame(0.75, $result['presence_penalty']);
        self::assertSame('snake-model', $result['recommended_model']);
    }

    #[Test]
    public function testNormalizeTaskPrefersSnakeCaseOverCamelCase(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult([
            'identifier' => 'prec-task',
            'name' => 'Precedence Task',
            'description' => 'Polished task description.',
            'category' => 'content',
            'prompt_template' => 'Snake template {{input}}',
            'promptTemplate' => 'Camel template {{input}}',
            'output_format' => 'json',
            'outputFormat' => 'html',
        ]);

        $result = $this->subject->generateTask('raw task request', $config);

        self::assertTrue($result['generated']);
        self::assertSame('Polished task description.', $result['description']);
        self::assertSame('Snake template {{input}}', $result['prompt_template']);
        self::assertSame('json', $result['output_format']);
    }

    /**
     * Run generateTaskWithChain with a fixed structured-completion payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function generateTaskWithChainFromPayload(array $payload): array
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->stubStructuredResult($payload);

        return $this->subject->generateTaskWithChain('raw chain request', $config);
    }

    #[Test]
    public function testNormalizeFullChainPrefersSnakeCaseOverCamelCase(): void
    {
        $result = $this->generateTaskWithChainFromPayload([
            'task' => [
                'identifier' => 'prec-chain',
                'name' => 'Precedence Chain',
                'description' => 'Polished chain description.',
                'category' => 'content',
                'prompt_template' => 'Snake {{input}}',
                'promptTemplate' => 'Camel {{input}}',
                'output_format' => 'json',
                'outputFormat' => 'html',
            ],
            'configuration' => [
                'identifier' => 'prec-chain-config',
                'name' => 'Chain Custom Name',
                'description' => 'Chain config description.',
                'system_prompt' => 'Snake sys.',
                'systemPrompt' => 'Camel sys.',
                'temperature' => 0.5,
                'max_tokens' => 999999,
                'maxTokens' => 500,
                'top_p' => 0.25,
                'topP' => 0.75,
                'frequency_penalty' => 0.5,
                'frequencyPenalty' => 1.5,
                'presence_penalty' => 0.75,
                'presencePenalty' => 1.75,
            ],
            'recommended_model_id' => 'snake-rec',
            'recommendedModelId' => 'camel-rec',
            'suggested_model' => [
                'name' => 'Suggested',
                'model_id' => 'snake-mid',
                'modelId' => 'camel-mid',
                'description' => 'Suggested desc.',
                'capabilities' => 'chat',
            ],
        ]);

        self::assertTrue($result['generated']);

        self::assertIsArray($result['task']);
        $task = $result['task'];
        self::assertSame('Polished chain description.', $task['description']);
        self::assertSame('Snake {{input}}', $task['prompt_template']);
        self::assertSame('json', $task['output_format']);

        self::assertIsArray($result['configuration']);
        $configuration = $result['configuration'];
        self::assertSame('Chain Custom Name', $configuration['name']);
        self::assertSame('Chain config description.', $configuration['description']);
        self::assertSame('Snake sys.', $configuration['system_prompt']);
        // 999999 is clamped to exactly 128000 — the upper-bound ±1 mutants differ.
        self::assertSame(128000, $configuration['max_tokens']);
        self::assertSame(0.25, $configuration['top_p']);
        self::assertSame(0.5, $configuration['frequency_penalty']);
        self::assertSame(0.75, $configuration['presence_penalty']);

        self::assertSame('snake-rec', $result['recommended_model_id']);

        self::assertIsArray($result['suggested_model']);
        $suggestedModel = $result['suggested_model'];
        self::assertSame('snake-mid', $suggestedModel['model_id']);
        self::assertSame('Suggested desc.', $suggestedModel['description']);
    }

    #[Test]
    public function testNormalizeFullChainHonorsCamelCaseOnlyKeys(): void
    {
        $result = $this->generateTaskWithChainFromPayload([
            'task' => [
                'identifier' => 'camel-chain',
                'name' => 'Camel Chain',
                'description' => 'Camel chain description.',
                'category' => 'developer',
                'promptTemplate' => 'Camel template {{input}}',
                'outputFormat' => 'html',
            ],
            'configuration' => [
                'identifier' => 'camel-chain-config',
                'name' => 'Camel Config',
                'description' => 'Camel config description.',
                'systemPrompt' => 'Camel sys.',
                'maxTokens' => 512,
                'topP' => 0.25,
                'frequencyPenalty' => 0.5,
                'presencePenalty' => 0.75,
            ],
            'recommendedModelId' => 'camel-rec',
            'suggested_model' => [
                'name' => 'Suggested',
                'modelId' => 'camel-mid',
                'description' => 'Camel suggested.',
                'capabilities' => 'chat',
            ],
        ]);

        self::assertTrue($result['generated']);

        self::assertIsArray($result['task']);
        $task = $result['task'];
        self::assertSame('Camel template {{input}}', $task['prompt_template']);
        self::assertSame('html', $task['output_format']);

        self::assertIsArray($result['configuration']);
        $configuration = $result['configuration'];
        self::assertSame('Camel sys.', $configuration['system_prompt']);
        self::assertSame(512, $configuration['max_tokens']);
        self::assertSame(0.25, $configuration['top_p']);
        self::assertSame(0.5, $configuration['frequency_penalty']);
        self::assertSame(0.75, $configuration['presence_penalty']);

        self::assertSame('camel-rec', $result['recommended_model_id']);

        self::assertIsArray($result['suggested_model']);
        $suggestedModel = $result['suggested_model'];
        self::assertSame('camel-mid', $suggestedModel['model_id']);
    }

    #[Test]
    public function testNormalizeFullChainAppliesDefaultsWhenConfigValuesMissing(): void
    {
        $result = $this->generateTaskWithChainFromPayload([
            'task' => [
                'identifier' => 'defaults-chain',
                'name' => 'Defaults Chain',
                'description' => 'Defaults chain description.',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => 'defaults-config',
                'name' => 'Defaults Config',
                'description' => 'd',
                'system_prompt' => 's',
            ],
            'recommended_model_id' => '',
            'suggested_model' => [
                'name' => 'S',
                'model_id' => 'mid',
                'description' => 'sd',
                'capabilities' => 'chat',
            ],
        ]);

        self::assertTrue($result['generated']);
        self::assertIsArray($result['configuration']);
        $configuration = $result['configuration'];
        self::assertSame(0.7, $configuration['temperature']);
        self::assertSame(4096, $configuration['max_tokens']);
        self::assertSame(1.0, $configuration['top_p']);
        self::assertSame(0.0, $configuration['frequency_penalty']);
        self::assertSame(0.0, $configuration['presence_penalty']);
    }

    // ==================== Fallback results: exact full shape ====================

    /**
     * 45-char description: the substr(…, 0, 40) window is observable — every
     * offset/length mutation and the unwrap produce a different identifier.
     */
    private function longFallbackDescription(): string
    {
        return 'b' . str_repeat('a', 44);
    }

    private function expectedTruncatedIdentifier(): string
    {
        return 'b' . str_repeat('a', 39);
    }

    #[Test]
    public function testFallbackConfigurationFullShape(): void
    {
        $this->stubNoDefaultConfig();
        $description = $this->longFallbackDescription();

        $result = $this->subject->generateConfiguration($description);

        self::assertSame([
            'identifier' => $this->expectedTruncatedIdentifier(),
            'name' => 'New Configuration',
            'description' => $description,
            'system_prompt' => 'You are a helpful assistant. ' . $description,
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => '',
            'generated' => false,
        ], $result);
    }

    #[Test]
    public function testFallbackTaskFullShape(): void
    {
        $this->stubNoDefaultConfig();
        $description = $this->longFallbackDescription();

        $result = $this->subject->generateTask($description);

        self::assertSame([
            'identifier' => $this->expectedTruncatedIdentifier(),
            'name' => 'New Task',
            'description' => $description,
            'category' => 'general',
            'prompt_template' => $description . "\n\n{{input}}",
            'output_format' => 'markdown',
            'generated' => false,
        ], $result);
    }

    #[Test]
    public function testFallbackTaskChainFullShape(): void
    {
        $this->stubNoDefaultConfig();
        $description = $this->longFallbackDescription();
        $identifier = $this->expectedTruncatedIdentifier();

        $result = $this->subject->generateTaskWithChain($description);

        self::assertSame([
            'task' => [
                'identifier' => $identifier,
                'name' => 'New Task',
                'description' => $description,
                'category' => 'general',
                'prompt_template' => $description . "\n\n{{input}}",
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => $identifier . '-config',
                'name' => 'New Task Configuration',
                'description' => 'Configuration for: ' . $description,
                'system_prompt' => 'You are a helpful assistant. ' . $description,
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'recommended_model_id' => '',
            'suggested_model' => [
                'name' => '',
                'model_id' => '',
                'description' => '',
                'capabilities' => 'chat',
            ],
            'generated' => false,
        ], $result);
    }

    // ==================== Full-chain context: slice window and label placement ====================

    #[Test]
    public function testGenerateTaskWithChainContextLimitsModelsToTenAndKeepsLabelOrder(): void
    {
        $config = $this->createConfigurationWithModel();

        $models = [];
        for ($i = 1; $i <= 11; ++$i) {
            $m = $this->createActiveModel('model-' . $i, 'Model ' . $i);
            $m->setDescription('Desc ' . $i);
            $models[] = $m;
        }

        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub($models));

        $existingConfig = new LlmConfiguration();
        $existingConfig->setName('Chain Ref');
        $existingConfig->setIdentifier('chain-ref');
        $existingConfig->setDescription('Ref desc');
        $this->configurationRepository->method('findAll')->willReturn([$existingConfig]);

        $captured = null;
        $this->stubStructuredCapturing([
            'task' => [
                'identifier' => 'ctx-chain',
                'name' => 'Ctx Chain',
                'description' => 'Ctx.',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => 'ctx-chain-config',
                'name' => 'Ctx Config',
                'description' => 'Ctx.',
                'system_prompt' => 'Ctx.',
            ],
            'recommended_model_id' => 'model-1',
            'suggested_model' => [
                'name' => 'M',
                'model_id' => 'model-1',
                'description' => 'd',
                'capabilities' => 'chat',
            ],
        ], $captured);

        $result = $this->subject->generateTaskWithChain('chain context capture', $config);

        self::assertTrue($result['generated']);

        self::assertNotNull($captured);
        $prompt = $captured['prompt'];

        // Label directly precedes the first model line (Concat order) and the
        // slice window is [0, 10): model 1 and 10 in, model 11 out. The needles
        // include the ':' so '(model-1)' cannot false-match inside '(model-11)'.
        self::assertStringContainsString(
            "Available models (prefer these):\n- Model 1 (model-1): Desc 1",
            $prompt,
        );
        self::assertStringContainsString('(model-10):', $prompt);
        self::assertStringNotContainsString('(model-11)', $prompt);

        // Configs label directly precedes the config line (Concat order).
        self::assertStringContainsString(
            "Existing configurations (for reference, avoid duplicate names):\n- Chain Ref (identifier: chain-ref): Ref desc",
            $prompt,
        );
    }

    // ==================== sanitizeIdentifier trims leading/trailing hyphens ====================

    #[Test]
    public function testGenerateConfigurationTrimsHyphensFromIdentifier(): void
    {
        $result = $this->generateConfigurationFromResult([
            'identifier' => '--wrapped--',
            'name' => 'Wrapped',
            'description' => 'Wrapped identifier.',
            'system_prompt' => 'Help.',
        ]);

        self::assertTrue($result['generated']);
        self::assertSame('wrapped', $result['identifier']);
    }
}
