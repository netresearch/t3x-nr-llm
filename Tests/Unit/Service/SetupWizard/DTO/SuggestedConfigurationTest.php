<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\DTO;

use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SuggestedConfiguration::class)]
class SuggestedConfigurationTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $additionalSettings = ['top_p' => 0.9, 'presence_penalty' => 0.1];

        $config = new SuggestedConfiguration(
            identifier: 'blog-summarizer',
            name: 'Blog Post Summarizer',
            description: 'Summarizes blog posts into bullet points',
            systemPrompt: 'You are a helpful assistant that summarizes content.',
            recommendedModelId: 'gpt-4o',
            temperature: 0.3,
            maxTokens: 2048,
            additionalSettings: $additionalSettings,
        );

        self::assertEquals('blog-summarizer', $config->identifier);
        self::assertEquals('Blog Post Summarizer', $config->name);
        self::assertEquals('Summarizes blog posts into bullet points', $config->description);
        self::assertEquals('You are a helpful assistant that summarizes content.', $config->systemPrompt);
        self::assertEquals('gpt-4o', $config->recommendedModelId);
        self::assertEquals(0.3, $config->temperature);
        self::assertEquals(2048, $config->maxTokens);
        self::assertEquals($additionalSettings, $config->additionalSettings);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'test-config',
            name: 'Test Configuration',
            description: 'A test',
            systemPrompt: 'Test prompt',
            recommendedModelId: 'test-model',
        );

        self::assertEquals(0.7, $config->temperature);
        self::assertEquals(4096, $config->maxTokens);
        self::assertEquals([], $config->additionalSettings);
    }

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $additionalSettings = ['frequency_penalty' => 0.5];

        $config = new SuggestedConfiguration(
            identifier: 'translator',
            name: 'Language Translator',
            description: 'Translates text between languages',
            systemPrompt: 'You are a translator.',
            recommendedModelId: 'claude-3-opus',
            temperature: 0.1,
            maxTokens: 8192,
            additionalSettings: $additionalSettings,
        );

        $array = $config->toArray();

        self::assertEquals([
            'identifier' => 'translator',
            'name' => 'Language Translator',
            'description' => 'Translates text between languages',
            'systemPrompt' => 'You are a translator.',
            'recommendedModelId' => 'claude-3-opus',
            'temperature' => 0.1,
            'maxTokens' => 8192,
            'additionalSettings' => $additionalSettings,
        ], $array);
    }

    #[Test]
    public function toArrayIncludesDefaultValues(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'minimal',
            name: 'Minimal Config',
            description: 'Minimal description',
            systemPrompt: 'Minimal prompt',
            recommendedModelId: 'gpt-3.5-turbo',
        );

        $array = $config->toArray();

        self::assertArrayHasKey('temperature', $array);
        self::assertArrayHasKey('maxTokens', $array);
        self::assertArrayHasKey('additionalSettings', $array);
        self::assertEquals(0.7, $array['temperature']);
        self::assertEquals(4096, $array['maxTokens']);
        self::assertEquals([], $array['additionalSettings']);
    }

    #[Test]
    public function toArrayReturnsCorrectStructureForJsonSerialization(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'json-test',
            name: 'JSON Test',
            description: 'Testing JSON serialization',
            systemPrompt: 'Test prompt',
            recommendedModelId: 'test-model',
        );

        $json = json_encode($config->toArray());

        self::assertIsString($json);
        self::assertJson($json);

        $decoded = json_decode($json, true);
        self::assertEquals('json-test', $decoded['identifier']);
        self::assertEquals('JSON Test', $decoded['name']);
    }

    #[Test]
    public function temperatureCanBeZero(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'deterministic',
            name: 'Deterministic Output',
            description: 'No randomness',
            systemPrompt: 'Be precise.',
            recommendedModelId: 'gpt-4',
            temperature: 0.0,
        );

        self::assertEquals(0.0, $config->temperature);
    }

    #[Test]
    public function temperatureCanBeHigh(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'creative',
            name: 'Creative Writer',
            description: 'High creativity',
            systemPrompt: 'Be creative.',
            recommendedModelId: 'gpt-4',
            temperature: 2.0,
        );

        self::assertEquals(2.0, $config->temperature);
    }

    #[Test]
    public function maxTokensCanBeVeryLarge(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'long-output',
            name: 'Long Output Generator',
            description: 'Generates long text',
            systemPrompt: 'Generate detailed responses.',
            recommendedModelId: 'claude-3-opus',
            maxTokens: 100000,
        );

        self::assertEquals(100000, $config->maxTokens);
    }
}
