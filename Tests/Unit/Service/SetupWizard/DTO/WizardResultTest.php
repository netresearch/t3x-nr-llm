<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\DTO;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Service\SetupWizard\DTO\WizardResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(WizardResult::class)]
final class WizardResultTest extends AbstractUnitTestCase
{
    private DetectedProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new DetectedProvider(
            adapterType: 'openai',
            suggestedName: 'OpenAI',
            endpoint: 'https://api.openai.com/v1',
        );
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $model = new DiscoveredModel(
            modelId: 'gpt-5.2',
            name: 'GPT-5.2',
        );
        $configuration = new SuggestedConfiguration(
            identifier: 'blog-summarizer',
            name: 'Blog Summarizer',
            description: 'Summarizes blog posts',
            systemPrompt: 'You are a helpful assistant.',
            recommendedModelId: 'gpt-5.2',
        );

        $result = new WizardResult(
            provider: $this->provider,
            models: [$model],
            configurations: [$configuration],
            connectionSuccessful: true,
            connectionMessage: 'Connection established successfully.',
        );

        self::assertSame($this->provider, $result->provider);
        self::assertSame([$model], $result->models);
        self::assertSame([$configuration], $result->configurations);
        self::assertTrue($result->connectionSuccessful);
        self::assertSame('Connection established successfully.', $result->connectionMessage);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $result = new WizardResult(provider: $this->provider);

        self::assertSame($this->provider, $result->provider);
        self::assertSame([], $result->models);
        self::assertSame([], $result->configurations);
        self::assertFalse($result->connectionSuccessful);
        self::assertSame('', $result->connectionMessage);
    }

    #[Test]
    public function toArrayReturnsCorrectStructureWithDefaults(): void
    {
        $result = new WizardResult(provider: $this->provider);

        $array = $result->toArray();

        self::assertArrayHasKey('provider', $array);
        self::assertArrayHasKey('models', $array);
        self::assertArrayHasKey('configurations', $array);
        self::assertArrayHasKey('connectionSuccessful', $array);
        self::assertArrayHasKey('connectionMessage', $array);

        self::assertIsArray($array['provider']);
        self::assertSame([], $array['models']);
        self::assertSame([], $array['configurations']);
        self::assertFalse($array['connectionSuccessful']);
        self::assertSame('', $array['connectionMessage']);
    }

    #[Test]
    public function toArrayIncludesProviderData(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            suggestedName: 'Anthropic Claude',
            endpoint: 'https://api.anthropic.com/v1',
            confidence: 0.9,
            metadata: ['version' => '2024-01-01'],
        );
        $result = new WizardResult(provider: $provider);

        $array = $result->toArray();

        self::assertSame([
            'adapterType' => 'anthropic',
            'suggestedName' => 'Anthropic Claude',
            'endpoint' => 'https://api.anthropic.com/v1',
            'confidence' => 0.9,
            'metadata' => ['version' => '2024-01-01'],
        ], $array['provider']);
    }

    #[Test]
    public function toArrayMapsModelsToArrays(): void
    {
        $model = new DiscoveredModel(
            modelId: 'gpt-5.2',
            name: 'GPT-5.2',
            description: 'Latest GPT model',
            capabilities: ['chat', 'tools'],
            contextLength: 128000,
            maxOutputTokens: 16384,
            costInput: 250,
            costOutput: 1000,
            recommended: true,
        );
        $result = new WizardResult(
            provider: $this->provider,
            models: [$model],
        );

        $array = $result->toArray();

        /** @var list<array<string, mixed>> $models */
        $models = $array['models'];
        self::assertCount(1, $models);
        self::assertSame([
            'modelId' => 'gpt-5.2',
            'name' => 'GPT-5.2',
            'description' => 'Latest GPT model',
            'capabilities' => ['chat', 'tools'],
            'contextLength' => 128000,
            'maxOutputTokens' => 16384,
            'costInput' => 250,
            'costOutput' => 1000,
            'recommended' => true,
        ], $models[0]);
    }

    #[Test]
    public function toArrayMapsConfigurationsToArrays(): void
    {
        $configuration = new SuggestedConfiguration(
            identifier: 'content-writer',
            name: 'Content Writer',
            description: 'Writes marketing content',
            systemPrompt: 'You are a creative writer.',
            recommendedModelId: 'gpt-5.2',
            temperature: 0.8,
            maxTokens: 2048,
            additionalSettings: ['tone' => 'professional'],
        );
        $result = new WizardResult(
            provider: $this->provider,
            configurations: [$configuration],
        );

        $array = $result->toArray();

        /** @var list<array<string, mixed>> $configurations */
        $configurations = $array['configurations'];
        self::assertCount(1, $configurations);
        self::assertSame([
            'identifier' => 'content-writer',
            'name' => 'Content Writer',
            'description' => 'Writes marketing content',
            'systemPrompt' => 'You are a creative writer.',
            'recommendedModelId' => 'gpt-5.2',
            'temperature' => 0.8,
            'maxTokens' => 2048,
            'additionalSettings' => ['tone' => 'professional'],
        ], $configurations[0]);
    }

    #[Test]
    public function toArrayMapsMultipleModelsAndConfigurations(): void
    {
        $models = [
            new DiscoveredModel(modelId: 'gpt-5.2', name: 'GPT-5.2'),
            new DiscoveredModel(modelId: 'gpt-5.2-mini', name: 'GPT-5.2 Mini'),
        ];
        $configurations = [
            new SuggestedConfiguration(
                identifier: 'summarizer',
                name: 'Summarizer',
                description: 'Summarizes text',
                systemPrompt: 'Summarize the following.',
                recommendedModelId: 'gpt-5.2-mini',
            ),
            new SuggestedConfiguration(
                identifier: 'analyst',
                name: 'Analyst',
                description: 'Analyzes data',
                systemPrompt: 'Analyze the following.',
                recommendedModelId: 'gpt-5.2',
            ),
        ];

        $result = new WizardResult(
            provider: $this->provider,
            models: $models,
            configurations: $configurations,
            connectionSuccessful: true,
            connectionMessage: 'OK',
        );

        $array = $result->toArray();

        /** @var list<array<string, mixed>> $models */
        $models = $array['models'];
        /** @var list<array<string, mixed>> $configurations */
        $configurations = $array['configurations'];
        self::assertCount(2, $models);
        self::assertCount(2, $configurations);
        self::assertTrue($array['connectionSuccessful']);
        self::assertSame('OK', $array['connectionMessage']);
    }

    #[Test]
    public function toArrayWithConnectionFailure(): void
    {
        $result = new WizardResult(
            provider: $this->provider,
            connectionSuccessful: false,
            connectionMessage: 'Connection refused: timeout after 30s',
        );

        $array = $result->toArray();

        self::assertFalse($array['connectionSuccessful']);
        self::assertSame('Connection refused: timeout after 30s', $array['connectionMessage']);
    }
}
