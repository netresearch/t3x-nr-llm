<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\Response;

use Netresearch\NrLlm\Controller\Backend\Response\DiscoveredModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\GeneratedConfigurationsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListItemResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderDetectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordDataResponse;
use Netresearch\NrLlm\Controller\Backend\Response\RecordListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TableListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TaskExecutionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TaskInputResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConfigurationResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Controller\Backend\Response\UsageResponse;
use Netresearch\NrLlm\Controller\Backend\Response\WizardSaveResponse;
use Netresearch\NrLlm\Controller\Backend\Response\WizardTestConnectionResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Service\Task\TaskExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Response DTOs to ensure proper JSON serialization.
 */
#[CoversClass(ErrorResponse::class)]
#[CoversClass(SuccessResponse::class)]
#[CoversClass(ToggleActiveResponse::class)]
#[CoversClass(UsageResponse::class)]
#[CoversClass(TestConfigurationResponse::class)]
#[CoversClass(ModelListItemResponse::class)]
#[CoversClass(ModelListResponse::class)]
#[CoversClass(ProviderModelsResponse::class)]
#[CoversClass(TestConnectionResponse::class)]
#[CoversClass(TableListResponse::class)]
#[CoversClass(RecordListResponse::class)]
#[CoversClass(RecordDataResponse::class)]
#[CoversClass(TaskExecutionResponse::class)]
#[CoversClass(TaskInputResponse::class)]
#[CoversClass(ProviderDetectionResponse::class)]
#[CoversClass(WizardTestConnectionResponse::class)]
#[CoversClass(DiscoveredModelsResponse::class)]
#[CoversClass(GeneratedConfigurationsResponse::class)]
#[CoversClass(WizardSaveResponse::class)]
final class ResponseDtoTest extends TestCase
{
    public function testErrorResponseContainsAllRequiredKeys(): void
    {
        $response = new ErrorResponse('Test error message');
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('success', $data);
        self::assertSame('Test error message', $data['error']);
        self::assertFalse($data['success']);
    }

    public function testSuccessResponseContainsSuccessKey(): void
    {
        $response = new SuccessResponse();
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertTrue($data['success']);
    }

    #[DataProvider('toggleActiveDataProvider')]
    public function testToggleActiveResponseContainsAllRequiredKeys(bool $isActive): void
    {
        $response = new ToggleActiveResponse(success: true, isActive: $isActive);
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('isActive', $data);
        self::assertTrue($data['success']);
        self::assertSame($isActive, $data['isActive']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function toggleActiveDataProvider(): iterable
    {
        yield 'active' => [true];
        yield 'inactive' => [false];
    }

    public function testUsageResponseContainsAllRequiredKeys(): void
    {
        $response = new UsageResponse(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
        );
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('promptTokens', $data);
        self::assertArrayHasKey('completionTokens', $data);
        self::assertArrayHasKey('totalTokens', $data);
        self::assertSame(100, $data['promptTokens']);
        self::assertSame(50, $data['completionTokens']);
        self::assertSame(150, $data['totalTokens']);
    }

    public function testUsageResponseFromStatistics(): void
    {
        $stats = new UsageStatistics(
            promptTokens: 200,
            completionTokens: 100,
            totalTokens: 300,
        );
        $response = UsageResponse::fromUsageStatistics($stats);
        $data = $response->jsonSerialize();

        self::assertSame(200, $data['promptTokens']);
        self::assertSame(100, $data['completionTokens']);
        self::assertSame(300, $data['totalTokens']);
    }

    public function testTestConfigurationResponseContainsAllRequiredKeys(): void
    {
        $usage = new UsageResponse(10, 20, 30);
        $response = new TestConfigurationResponse(
            success: true,
            content: 'Hello world',
            model: 'gpt-4',
            usage: $usage,
        );
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('content', $data);
        self::assertArrayHasKey('model', $data);
        self::assertArrayHasKey('usage', $data);
        self::assertTrue($data['success']);
        self::assertSame('Hello world', $data['content']);
        self::assertSame('gpt-4', $data['model']);
        self::assertArrayHasKey('promptTokens', $data['usage']);
        self::assertArrayHasKey('completionTokens', $data['usage']);
        self::assertArrayHasKey('totalTokens', $data['usage']);
    }

    public function testModelListItemResponseContainsAllRequiredKeys(): void
    {
        $response = new ModelListItemResponse(
            uid: 1,
            identifier: 'test-model',
            name: 'Test Model',
            modelId: 'gpt-4',
            isDefault: true,
        );
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('uid', $data);
        self::assertArrayHasKey('identifier', $data);
        self::assertArrayHasKey('name', $data);
        self::assertArrayHasKey('modelId', $data);
        self::assertArrayHasKey('isDefault', $data);
        self::assertSame(1, $data['uid']);
        self::assertSame('test-model', $data['identifier']);
        self::assertSame('Test Model', $data['name']);
        self::assertSame('gpt-4', $data['modelId']);
        self::assertTrue($data['isDefault']);
    }

    public function testModelListItemResponseFromModel(): void
    {
        $model = self::createStub(Model::class);
        $model->method('getUid')->willReturn(42);
        $model->method('getIdentifier')->willReturn('my-model');
        $model->method('getName')->willReturn('My Model');
        $model->method('getModelId')->willReturn('claude-3');
        $model->method('isDefault')->willReturn(false);

        $response = ModelListItemResponse::fromModel($model);
        $data = $response->jsonSerialize();

        self::assertSame(42, $data['uid']);
        self::assertSame('my-model', $data['identifier']);
        self::assertSame('My Model', $data['name']);
        self::assertSame('claude-3', $data['modelId']);
        self::assertFalse($data['isDefault']);
    }

    public function testModelListResponseContainsAllRequiredKeys(): void
    {
        $item = new ModelListItemResponse(1, 'test', 'Test', 'gpt-4', false);
        $response = new ModelListResponse(success: true, models: [$item]);
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('models', $data);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['models']);
    }

    public function testModelListResponseFromModels(): void
    {
        $model1 = self::createStub(Model::class);
        $model1->method('getUid')->willReturn(1);
        $model1->method('getIdentifier')->willReturn('model-1');
        $model1->method('getName')->willReturn('Model 1');
        $model1->method('getModelId')->willReturn('gpt-4');
        $model1->method('isDefault')->willReturn(true);

        $model2 = self::createStub(Model::class);
        $model2->method('getUid')->willReturn(2);
        $model2->method('getIdentifier')->willReturn('model-2');
        $model2->method('getName')->willReturn('Model 2');
        $model2->method('getModelId')->willReturn('claude-3');
        $model2->method('isDefault')->willReturn(false);

        $response = ModelListResponse::fromModels([$model1, $model2]);
        $data = $response->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertCount(2, $data['models']);
    }

    public function testProviderModelsResponseContainsAllRequiredKeys(): void
    {
        $response = new ProviderModelsResponse(
            success: true,
            models: ['gpt-4' => 'GPT-4', 'gpt-3.5' => 'GPT-3.5'],
            defaultModel: 'gpt-4',
        );
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('models', $data);
        self::assertArrayHasKey('defaultModel', $data);
        self::assertTrue($data['success']);
        self::assertSame(['gpt-4' => 'GPT-4', 'gpt-3.5' => 'GPT-3.5'], $data['models']);
        self::assertSame('gpt-4', $data['defaultModel']);
    }

    public function testTestConnectionResponseContainsAllRequiredKeys(): void
    {
        $response = new TestConnectionResponse(
            success: true,
            message: 'Connection successful',
            models: ['gpt-4', 'gpt-3.5'],
        );
        $data = $response->jsonSerialize();

        self::assertArrayHasKey('success', $data);
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('models', $data);
        self::assertTrue($data['success']);
        self::assertSame('Connection successful', $data['message']);
        self::assertSame(['gpt-4', 'gpt-3.5'], $data['models']);
    }

    public function testTestConnectionResponseFromResult(): void
    {
        $result = [
            'success' => true,
            'message' => 'OK',
            'models' => ['model-a' => 'Model A', 'model-b' => 'Model B'],
        ];
        $response = TestConnectionResponse::fromResult($result);
        $data = $response->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertSame('OK', $data['message']);
        // Models should be converted to just keys (IDs)
        self::assertSame(['model-a', 'model-b'], $data['models']);
    }

    public function testTestConnectionResponseFromResultWithoutModels(): void
    {
        $result = [
            'success' => false,
            'message' => 'Connection failed',
        ];
        $response = TestConnectionResponse::fromResult($result);
        $data = $response->jsonSerialize();

        self::assertFalse($data['success']);
        self::assertSame('Connection failed', $data['message']);
        self::assertSame([], $data['models']);
    }

    // ========================================
    // Task pathway responses (slice 13d)
    // ========================================

    public function testTableListResponseShape(): void
    {
        $tables = [
            ['name' => 'tt_content', 'label' => 'Tt Content'],
            ['name' => 'sys_log', 'label' => 'System: Log'],
        ];
        $data = (new TableListResponse(tables: $tables))->jsonSerialize();

        // success first matches the controller's pre-typed literal order
        self::assertSame(['success', 'tables'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame($tables, $data['tables']);
    }

    public function testRecordListResponseShape(): void
    {
        $records = [
            ['uid' => 1, 'label' => 'First'],
            ['uid' => 42, 'label' => '[UID 42]'],
        ];
        $data = (new RecordListResponse(
            records: $records,
            labelField: 'header',
            total: 2,
        ))->jsonSerialize();

        self::assertSame(['success', 'records', 'labelField', 'total'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame($records, $data['records']);
        self::assertSame('header', $data['labelField']);
        self::assertSame(2, $data['total']);
    }

    public function testRecordDataResponseShape(): void
    {
        $payload = '[{"uid":1}]';
        $data = (new RecordDataResponse(
            data: $payload,
            recordCount: 1,
        ))->jsonSerialize();

        self::assertSame(['success', 'data', 'recordCount'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame($payload, $data['data']);
        self::assertSame(1, $data['recordCount']);
    }

    public function testTaskExecutionResponseShape(): void
    {
        $usage = new UsageStatistics(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
        );
        $data = (new TaskExecutionResponse(
            content: 'Hello',
            model: 'gpt-4',
            outputFormat: 'markdown',
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            totalTokens: $usage->totalTokens,
        ))->jsonSerialize();

        self::assertSame(['success', 'content', 'model', 'outputFormat', 'usage'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame('Hello', $data['content']);
        self::assertSame('gpt-4', $data['model']);
        self::assertSame('markdown', $data['outputFormat']);
        self::assertSame(
            ['promptTokens' => 100, 'completionTokens' => 50, 'totalTokens' => 150],
            $data['usage'],
        );
    }

    public function testTaskExecutionResponseFromResult(): void
    {
        $result = new TaskExecutionResult(
            content: 'World',
            model: 'claude-3-5-sonnet',
            outputFormat: 'plain',
            usage: new UsageStatistics(promptTokens: 7, completionTokens: 13, totalTokens: 20),
        );
        $data = TaskExecutionResponse::fromResult($result)->jsonSerialize();

        self::assertSame('World', $data['content']);
        self::assertSame('claude-3-5-sonnet', $data['model']);
        self::assertSame('plain', $data['outputFormat']);
        self::assertSame(
            ['promptTokens' => 7, 'completionTokens' => 13, 'totalTokens' => 20],
            $data['usage'],
        );
    }

    public function testTaskInputResponseShape(): void
    {
        $data = (new TaskInputResponse(
            inputData: 'log line 1',
            inputType: 'syslog',
            isEmpty: false,
        ))->jsonSerialize();

        self::assertSame(['success', 'inputData', 'inputType', 'isEmpty'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame('log line 1', $data['inputData']);
        self::assertSame('syslog', $data['inputType']);
        self::assertFalse($data['isEmpty']);
    }

    public function testErrorResponseKeyOrder(): void
    {
        // `success` first matches the natural read order and the
        // pre-typed JSON literals every controller used before
        // slice 13d. Tests that compare full body shape via
        // assertSame() depend on this ordering.
        $data = (new ErrorResponse('boom'))->jsonSerialize();

        self::assertSame(['success', 'error'], array_keys($data));
        self::assertFalse($data['success']);
        self::assertSame('boom', $data['error']);
    }

    // ========================================
    // Setup Wizard pathway responses (slice 21)
    // ========================================

    public function testProviderDetectionResponseShape(): void
    {
        $data = (new ProviderDetectionResponse(
            provider: [
                'adapterType'   => 'openai',
                'suggestedName' => 'OpenAI',
                'endpoint'      => 'https://api.openai.com/v1',
                'confidence'    => 1.0,
                'metadata'      => [],
            ],
        ))->jsonSerialize();

        self::assertSame(['success', 'provider'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame('openai', $data['provider']['adapterType']);
        self::assertSame('https://api.openai.com/v1', $data['provider']['endpoint']);
    }

    public function testProviderDetectionResponseFromDetectedProvider(): void
    {
        $detected = new DetectedProvider(
            adapterType: 'anthropic',
            suggestedName: 'Anthropic',
            endpoint: 'https://api.anthropic.com',
            confidence: 0.9,
            metadata: ['region' => 'us'],
        );
        $data = ProviderDetectionResponse::fromDetectedProvider($detected)->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertSame($detected->toArray(), $data['provider']);
    }

    public function testWizardTestConnectionResponseShape(): void
    {
        $data = (new WizardTestConnectionResponse(
            success: true,
            message: 'Connection OK',
        ))->jsonSerialize();

        // Byte-for-byte parity with the pre-DTO inline literal —
        // `models` is intentionally absent so the wizard JSON body
        // stays exactly `{success, message}`.
        self::assertSame(['success', 'message'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame('Connection OK', $data['message']);
    }

    public function testWizardTestConnectionResponseFromResult(): void
    {
        $data = WizardTestConnectionResponse::fromResult([
            'success' => false,
            'message' => 'Auth failed',
        ])->jsonSerialize();

        self::assertFalse($data['success']);
        self::assertSame('Auth failed', $data['message']);
    }

    public function testDiscoveredModelsResponseShape(): void
    {
        $data = (new DiscoveredModelsResponse(
            models: [
                ['modelId' => 'gpt-4', 'name' => 'GPT-4', 'recommended' => true],
            ],
        ))->jsonSerialize();

        self::assertSame(['success', 'models'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertCount(1, $data['models']);
        self::assertSame('gpt-4', $data['models'][0]['modelId']);
    }

    public function testDiscoveredModelsResponseFromDiscoveredModels(): void
    {
        $a = new DiscoveredModel(modelId: 'gpt-4', name: 'GPT-4');
        $b = new DiscoveredModel(modelId: 'claude-3', name: 'Claude 3');

        $data = DiscoveredModelsResponse::fromDiscoveredModels([$a, $b])->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertCount(2, $data['models']);
        self::assertSame('gpt-4', $data['models'][0]['modelId']);
        self::assertSame('claude-3', $data['models'][1]['modelId']);
        // Inner shape mirrors DiscoveredModel::toArray()
        self::assertArrayHasKey('capabilities', $data['models'][0]);
        self::assertArrayHasKey('contextLength', $data['models'][0]);
    }

    public function testDiscoveredModelsResponseFromEmptyList(): void
    {
        $data = DiscoveredModelsResponse::fromDiscoveredModels([])->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertSame([], $data['models']);
    }

    public function testGeneratedConfigurationsResponseShape(): void
    {
        $data = (new GeneratedConfigurationsResponse(
            configurations: [
                ['identifier' => 'summarizer', 'name' => 'Summarizer'],
            ],
        ))->jsonSerialize();

        self::assertSame(['success', 'configurations'], array_keys($data));
        self::assertTrue($data['success']);
        self::assertCount(1, $data['configurations']);
        self::assertSame('summarizer', $data['configurations'][0]['identifier']);
    }

    public function testGeneratedConfigurationsResponseFromSuggestedConfigurations(): void
    {
        $cfg = new SuggestedConfiguration(
            identifier: 'translator',
            name: 'Translator',
            description: 'Translates text',
            systemPrompt: 'You are a translator.',
            recommendedModelId: 'gpt-4',
        );

        $data = GeneratedConfigurationsResponse::fromSuggestedConfigurations([$cfg])->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertCount(1, $data['configurations']);
        self::assertSame($cfg->toArray(), $data['configurations'][0]);
    }

    public function testGeneratedConfigurationsResponseFromEmptyList(): void
    {
        $data = GeneratedConfigurationsResponse::fromSuggestedConfigurations([])->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertSame([], $data['configurations']);
    }

    public function testWizardSaveResponseShape(): void
    {
        $data = (new WizardSaveResponse(
            message: 'Configuration saved successfully',
            providerUid: 17,
            providerName: 'My OpenAI',
            modelsCount: 3,
            configurationsCount: 2,
        ))->jsonSerialize();

        self::assertSame(
            ['success', 'message', 'provider', 'modelsCount', 'configurationsCount'],
            array_keys($data),
        );
        self::assertTrue($data['success']);
        self::assertSame('Configuration saved successfully', $data['message']);
        self::assertSame(['uid' => 17, 'name' => 'My OpenAI'], $data['provider']);
        self::assertSame(3, $data['modelsCount']);
        self::assertSame(2, $data['configurationsCount']);
    }

    public function testWizardSaveResponseFromProvider(): void
    {
        $provider = self::createStub(Provider::class);
        $provider->method('getUid')->willReturn(42);
        $provider->method('getName')->willReturn('Production OpenAI');

        $data = WizardSaveResponse::fromProvider(
            provider: $provider,
            modelsCount: 5,
            configurationsCount: 0,
        )->jsonSerialize();

        self::assertTrue($data['success']);
        self::assertSame('Configuration saved successfully', $data['message']);
        self::assertSame(['uid' => 42, 'name' => 'Production OpenAI'], $data['provider']);
        self::assertSame(5, $data['modelsCount']);
        self::assertSame(0, $data['configurationsCount']);
    }

    public function testWizardSaveResponseFromProviderPassesNullUidThrough(): void
    {
        // Pre-DTO controller code returned `$provider->getUid()`
        // directly; substituting `0` would change the wire shape and
        // break the frontend's "did the entity get a uid?" check.
        // Use a real Provider instance whose `getUid()` returns null
        // (the default for an unpersisted entity).
        $provider = new Provider();
        $provider->setName('Detached');

        self::assertNull($provider->getUid());

        $data = WizardSaveResponse::fromProvider(
            provider: $provider,
            modelsCount: 1,
            configurationsCount: 1,
        )->jsonSerialize();

        self::assertNull($data['provider']['uid']);
        self::assertSame('Detached', $data['provider']['name']);
    }

    public function testWizardSaveResponseFromProviderEmitsPersistedUid(): void
    {
        // Mirror image of the previous test: when the entity has a
        // uid, that uid round-trips through the DTO unchanged.
        $provider = new Provider();
        $provider->setName('Production OpenAI');
        $provider->_setProperty('uid', 42);

        $data = WizardSaveResponse::fromProvider(
            provider: $provider,
            modelsCount: 5,
            configurationsCount: 0,
        )->jsonSerialize();

        self::assertSame(42, $data['provider']['uid']);
        self::assertSame('Production OpenAI', $data['provider']['name']);
    }
}
