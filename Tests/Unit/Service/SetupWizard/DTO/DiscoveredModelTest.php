<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\DTO;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DiscoveredModel::class)]
class DiscoveredModelTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $capabilities = ['chat', 'vision', 'tools'];
        $model = new DiscoveredModel(
            modelId: 'gpt-4o',
            name: 'GPT-4 Omni',
            description: 'Multimodal model with vision and tools',
            capabilities: $capabilities,
            contextLength: 128000,
            maxOutputTokens: 16384,
            costInput: 250,
            costOutput: 1000,
            recommended: true,
        );

        self::assertEquals('gpt-4o', $model->modelId);
        self::assertEquals('GPT-4 Omni', $model->name);
        self::assertEquals('Multimodal model with vision and tools', $model->description);
        self::assertEquals($capabilities, $model->capabilities);
        self::assertEquals(128000, $model->contextLength);
        self::assertEquals(16384, $model->maxOutputTokens);
        self::assertEquals(250, $model->costInput);
        self::assertEquals(1000, $model->costOutput);
        self::assertTrue($model->recommended);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $model = new DiscoveredModel(
            modelId: 'llama3.2',
            name: 'Llama 3.2',
        );

        self::assertEquals('', $model->description);
        self::assertEquals(['chat'], $model->capabilities);
        self::assertEquals(0, $model->contextLength);
        self::assertEquals(0, $model->maxOutputTokens);
        self::assertEquals(0, $model->costInput);
        self::assertEquals(0, $model->costOutput);
        self::assertFalse($model->recommended);
    }

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $capabilities = ['chat', 'completion', 'embeddings'];
        $model = new DiscoveredModel(
            modelId: 'claude-4-opus',
            name: 'Claude 4 Opus',
            description: 'Most capable Claude model',
            capabilities: $capabilities,
            contextLength: 200000,
            maxOutputTokens: 8192,
            costInput: 1500,
            costOutput: 7500,
            recommended: true,
        );

        $array = $model->toArray();

        self::assertEquals([
            'modelId' => 'claude-4-opus',
            'name' => 'Claude 4 Opus',
            'description' => 'Most capable Claude model',
            'capabilities' => $capabilities,
            'contextLength' => 200000,
            'maxOutputTokens' => 8192,
            'costInput' => 1500,
            'costOutput' => 7500,
            'recommended' => true,
        ], $array);
    }

    #[Test]
    public function toArrayIncludesDefaultValues(): void
    {
        $model = new DiscoveredModel(
            modelId: 'mistral-large',
            name: 'Mistral Large',
        );

        $array = $model->toArray();

        self::assertArrayHasKey('description', $array);
        self::assertArrayHasKey('capabilities', $array);
        self::assertArrayHasKey('contextLength', $array);
        self::assertArrayHasKey('recommended', $array);
        self::assertEquals('', $array['description']);
        self::assertEquals(['chat'], $array['capabilities']);
        self::assertEquals(0, $array['contextLength']);
        self::assertFalse($array['recommended']);
    }

    #[Test]
    public function toArrayReturnsCorrectStructureForJsonSerialization(): void
    {
        $model = new DiscoveredModel(
            modelId: 'test-model',
            name: 'Test Model',
        );

        $json = json_encode($model->toArray());

        self::assertIsString($json);
        self::assertJson($json);

        $decoded = json_decode($json, true);
        self::assertEquals('test-model', $decoded['modelId']);
        self::assertEquals('Test Model', $decoded['name']);
    }
}
