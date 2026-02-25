<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\Response;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListItemResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConfigurationResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Controller\Backend\Response\UsageResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
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
}
