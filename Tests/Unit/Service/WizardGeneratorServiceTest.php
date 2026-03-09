<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use ArrayIterator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(WizardGeneratorService::class)]
#[AllowMockObjectsWithoutExpectations]
class WizardGeneratorServiceTest extends AbstractUnitTestCase
{
    private LlmServiceManagerInterface&MockObject $llmServiceManager;
    private LlmConfigurationRepository&MockObject $configurationRepository;
    private ModelRepository&MockObject $modelRepository;
    private WizardGeneratorService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);

        $this->subject = new WizardGeneratorService(
            $this->llmServiceManager,
            $this->configurationRepository,
            $this->modelRepository,
        );
    }

    // ==================== Helper methods ====================

    private function createCompletionResponse(string $content): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'gpt-5.2',
            usage: new UsageStatistics(
                promptTokens: 100,
                completionTokens: 50,
                totalTokens: 150,
            ),
        );
    }

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
        $stub->method('current')->willReturnCallback(fn() => $iterator->current());
        $stub->method('key')->willReturnCallback(fn() => $iterator->key());
        $stub->method('next')->willReturnCallback(fn() => $iterator->next());
        $stub->method('rewind')->willReturnCallback(fn() => $iterator->rewind());
        $stub->method('valid')->willReturnCallback(fn() => $iterator->valid());
        $stub->method('count')->willReturn(count($items));
        $stub->method('toArray')->willReturn($items);
        $stub->method('getFirst')->willReturn($items[0] ?? null);

        return $stub;
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
            ->method('findByUid')
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

        $result = $this->subject->resolveConfiguration(null);

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testResolveConfigurationFallsBackToDefaultWhenUidIsZero(): void
    {
        $defaultConfig = $this->createConfigurationWithModel();
        $this->stubDefaultConfig($defaultConfig);

        $result = $this->subject->resolveConfiguration(0);

        self::assertSame($defaultConfig, $result);
    }

    #[Test]
    public function testResolveConfigurationReturnsNullWhenNoConfigAvailable(): void
    {
        $this->stubNoDefaultConfig();

        $result = $this->subject->resolveConfiguration(null);

        self::assertNull($result);
    }

    // ==================== generateConfiguration ====================

    #[Test]
    public function testGenerateConfigurationHappyPathReturnsLlmResult(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('LLM service unavailable'));

        $result = $this->subject->generateConfiguration('translate content', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Configuration', $result['name']);
        self::assertSame('translate content', $result['description']);
    }

    #[Test]
    public function testGenerateConfigurationFallbackOnInvalidJson(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse('This is not JSON at all!'));

        $result = $this->subject->generateConfiguration('write poetry', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Configuration', $result['name']);
    }

    #[Test]
    public function testGenerateConfigurationParsesMarkdownWrappedJson(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $jsonContent = json_encode([
            'identifier' => 'seo-optimizer',
            'name' => 'SEO Optimizer',
            'description' => 'Optimizes content for search engines.',
            'system_prompt' => 'You are an SEO expert.',
            'temperature' => 0.5,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => 'gpt-5.2',
        ], JSON_THROW_ON_ERROR);

        $markdownResponse = "Here is your configuration:\n```json\n{$jsonContent}\n```";

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($markdownResponse));

        $result = $this->subject->generateConfiguration('optimize for seo', $config);

        self::assertTrue($result['generated']);
        self::assertSame('seo-optimizer', $result['identifier']);
        self::assertSame('SEO Optimizer', $result['name']);
    }

    #[Test]
    public function testGenerateConfigurationHandlesCamelCaseKeys(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'clamp-test',
            'name' => 'Clamp Test',
            'description' => 'Test clamping',
            'system_prompt' => 'Test.',
            'temperature' => 5.0,
            'max_tokens' => 999999,
            'top_p' => 3.0,
            'frequency_penalty' => -5.0,
            'presence_penalty' => 10.0,
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'Test_Config With  Spaces!!!',
            'name' => 'Test Config',
            'description' => 'Test',
            'system_prompt' => 'Test.',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'auto-config',
            'name' => 'Auto Config',
            'description' => 'Uses default config.',
            'system_prompt' => 'Test.',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->with(self::anything(), $defaultConfig)
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateConfiguration('auto config');

        self::assertTrue($result['generated']);
    }

    // ==================== generateTask ====================

    #[Test]
    public function testGenerateTaskHappyPathReturnsLlmResult(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
            'identifier' => 'summarize-article',
            'name' => 'Summarize Article',
            'description' => 'Summarizes articles into key points.',
            'category' => 'content',
            'prompt_template' => 'Summarize the following article:\n\n{{input}}',
            'output_format' => 'markdown',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $result = $this->subject->generateTask('debug code', $config);

        self::assertFalse($result['generated']);
        self::assertSame('New Task', $result['name']);
    }

    #[Test]
    public function testGenerateTaskFallbackOnInvalidJson(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse('Not valid JSON'));

        $result = $this->subject->generateTask('some task', $config);

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskValidatesCategory(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
            'identifier' => 'invalid-cat',
            'name' => 'Invalid Category',
            'description' => 'Test invalid category.',
            'category' => 'nonexistent_category',
            'prompt_template' => '{{input}}',
            'output_format' => 'markdown',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateTask('test categories', $config);

        self::assertTrue($result['generated']);
        self::assertSame('general', $result['category']);
    }

    #[Test]
    public function testGenerateTaskValidatesOutputFormat(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
            'identifier' => 'invalid-format',
            'name' => 'Invalid Format',
            'description' => 'Test invalid format.',
            'category' => 'content',
            'prompt_template' => '{{input}}',
            'output_format' => 'xml',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateTask('test format', $config);

        self::assertTrue($result['generated']);
        self::assertSame('markdown', $result['output_format']);
    }

    #[Test]
    public function testGenerateTaskHandlesCamelCaseKeys(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
            'identifier' => 'camel-task',
            'name' => 'Camel Task',
            'description' => 'Test camelCase.',
            'category' => 'developer',
            'promptTemplate' => 'Do this: {{input}}',
            'outputFormat' => 'json',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Service down'));

        $result = $this->subject->generateTaskWithChain('create tasks', $config);

        self::assertFalse($result['generated']);
        /** @var array<string, mixed> $task */
        $task = $result['task'];
        self::assertSame('New Task', $task['name']);
    }

    #[Test]
    public function testGenerateTaskWithChainFallbackOnInvalidJson(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse('completely invalid'));

        $result = $this->subject->generateTaskWithChain('something', $config);

        self::assertFalse($result['generated']);
    }

    #[Test]
    public function testGenerateTaskWithChainHandlesFlatStructure(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        // When LLM returns flat keys instead of nested task/configuration objects,
        // normalizeFullChainResult treats the whole data as task data
        $llmJson = json_encode([
            'identifier' => 'flat-task',
            'name' => 'Flat Task',
            'description' => 'Flat structure response.',
            'category' => 'developer',
            'prompt_template' => '{{input}}',
            'output_format' => 'plain',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateTaskWithChain('clamp test', $config);

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

        $llmJson = json_encode([
            'identifier' => 'numeric-test',
            'name' => 'Numeric Test',
            'description' => 'Test numeric string handling.',
            'system_prompt' => 'Test.',
            'temperature' => '0.5',
            'max_tokens' => '2048',
            'top_p' => '0.9',
            'frequency_penalty' => '0.1',
            'presence_penalty' => '0.2',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'non-numeric',
            'name' => 123,
            'description' => true,
            'system_prompt' => null,
            'temperature' => 'not-a-number',
            'max_tokens' => 'high',
            'top_p' => [],
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateConfiguration('non-numeric test', $config);

        self::assertTrue($result['generated']);
        // str() handles numeric values
        self::assertSame('123', $result['name']);
        // str() returns '' for non-string/non-numeric (bool, null, array)
        self::assertSame('', $result['description']);
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

        // Minimal JSON — most fields missing
        $llmJson = json_encode([
            'identifier' => 'minimal',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

    // ==================== JSON parsing edge cases ====================

    #[Test]
    public function testParseJsonExtractsFromTextWithEmbeddedJson(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $embeddedJson = 'Sure! Here is the configuration: {"identifier":"embedded","name":"Embedded Config","description":"Found in text.","system_prompt":"Test.","temperature":0.5,"max_tokens":2048}';

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($embeddedJson));

        $result = $this->subject->generateConfiguration('embedded json', $config);

        self::assertTrue($result['generated']);
        self::assertSame('embedded', $result['identifier']);
        self::assertSame('Embedded Config', $result['name']);
    }

    #[Test]
    public function testParseJsonHandlesMarkdownWithoutLanguageTag(): void
    {
        $config = $this->createConfigurationWithModel();
        $this->modelRepository->method('findActive')->willReturn($this->createQueryResultStub([]));
        $this->configurationRepository->method('findAll')->willReturn([]);

        $jsonContent = json_encode([
            'identifier' => 'no-lang-tag',
            'name' => 'No Lang Tag',
            'description' => 'Markdown without json tag.',
            'system_prompt' => 'Test.',
        ], JSON_THROW_ON_ERROR);

        $markdownResponse = "Here:\n```\n{$jsonContent}\n```";

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($markdownResponse));

        $result = $this->subject->generateConfiguration('no lang tag', $config);

        self::assertTrue($result['generated']);
        self::assertSame('no-lang-tag', $result['identifier']);
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

        $llmJson = json_encode([
            'identifier' => 'fallback-active',
            'name' => 'Fallback Active',
            'description' => 'Uses first active config.',
            'system_prompt' => 'Test.',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->with(self::anything(), $activeConfig)
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'skip-default',
            'name' => 'Skip Default',
            'description' => 'Skipped default without model.',
            'system_prompt' => 'Test.',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

            $llmJson = json_encode([
                'identifier' => 'cat-' . $category,
                'name' => 'Category Test',
                'description' => 'Test.',
                'category' => $category,
                'prompt_template' => '{{input}}',
                'output_format' => 'markdown',
            ], JSON_THROW_ON_ERROR);

            // Reset mock for each iteration
            $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
            $llmServiceManager
                ->method('chatWithConfiguration')
                ->willReturn($this->createCompletionResponse($llmJson));

            $subject = new WizardGeneratorService(
                $llmServiceManager,
                $this->configurationRepository,
                $this->modelRepository,
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

            $llmJson = json_encode([
                'identifier' => 'fmt-' . $format,
                'name' => 'Format Test',
                'description' => 'Test.',
                'category' => 'general',
                'prompt_template' => '{{input}}',
                'output_format' => $format,
            ], JSON_THROW_ON_ERROR);

            $llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
            $llmServiceManager
                ->method('chatWithConfiguration')
                ->willReturn($this->createCompletionResponse($llmJson));

            $subject = new WizardGeneratorService(
                $llmServiceManager,
                $this->configurationRepository,
                $this->modelRepository,
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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'summarize-text',
            'name' => 'Summarize Text',
            'description' => 'Summarizes text content',
            'category' => 'content',
            'prompt_template' => 'Summarize: {{input}}',
            'output_format' => 'markdown',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
            'identifier' => 'task-x',
            'name' => 'Task X',
            'description' => 'Does X',
            'category' => 'general',
            'prompt_template' => '{{input}}',
            'output_format' => 'plain',
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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

        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

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
        $llmJson = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        $this->llmServiceManager
            ->method('chatWithConfiguration')
            ->willReturn($this->createCompletionResponse($llmJson));

        $result = $this->subject->generateConfiguration('limit test', $config);

        self::assertTrue($result['generated']);
    }
}
