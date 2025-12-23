<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ChatOptions::class)]
class ChatOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $options = new ChatOptions(
            temperature: 0.7,
            maxTokens: 1000,
            topP: 0.9,
            frequencyPenalty: 0.5,
            presencePenalty: 0.5,
            responseFormat: 'json',
            systemPrompt: 'You are helpful.',
            stopSequences: ['END'],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertEquals(0.7, $options->getTemperature());
        $this->assertEquals(1000, $options->getMaxTokens());
        $this->assertEquals(0.9, $options->getTopP());
        $this->assertEquals(0.5, $options->getFrequencyPenalty());
        $this->assertEquals(0.5, $options->getPresencePenalty());
        $this->assertEquals('json', $options->getResponseFormat());
        $this->assertEquals('You are helpful.', $options->getSystemPrompt());
        $this->assertEquals(['END'], $options->getStopSequences());
        $this->assertEquals('openai', $options->getProvider());
        $this->assertEquals('gpt-4o', $options->getModel());
    }

    #[Test]
    #[DataProvider('invalidTemperatureProvider')]
    public function constructorThrowsForInvalidTemperature(float $temperature): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        new ChatOptions(temperature: $temperature);
    }

    public static function invalidTemperatureProvider(): array
    {
        return [
            'negative' => [-0.1],
            'too high' => [2.1],
            'way too high' => [5.0],
        ];
    }

    #[Test]
    #[DataProvider('validTemperatureProvider')]
    public function constructorAcceptsValidTemperature(float $temperature): void
    {
        $options = new ChatOptions(temperature: $temperature);

        $this->assertEquals($temperature, $options->getTemperature());
    }

    public static function validTemperatureProvider(): array
    {
        return [
            'zero' => [0.0],
            'low' => [0.2],
            'mid' => [1.0],
            'high' => [2.0],
        ];
    }

    #[Test]
    public function constructorThrowsForNegativeMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be a positive integer');

        new ChatOptions(maxTokens: 0);
    }

    #[Test]
    #[DataProvider('invalidTopPProvider')]
    public function constructorThrowsForInvalidTopP(float $topP): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('top_p must be between 0 and 1');

        new ChatOptions(topP: $topP);
    }

    public static function invalidTopPProvider(): array
    {
        return [
            'negative' => [-0.1],
            'too high' => [1.1],
        ];
    }

    #[Test]
    #[DataProvider('invalidPenaltyProvider')]
    public function constructorThrowsForInvalidFrequencyPenalty(float $penalty): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('frequency_penalty must be between -2 and 2');

        new ChatOptions(frequencyPenalty: $penalty);
    }

    #[Test]
    #[DataProvider('invalidPenaltyProvider')]
    public function constructorThrowsForInvalidPresencePenalty(float $penalty): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('presence_penalty must be between -2 and 2');

        new ChatOptions(presencePenalty: $penalty);
    }

    public static function invalidPenaltyProvider(): array
    {
        return [
            'too low' => [-2.1],
            'too high' => [2.1],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidResponseFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response_format must be one of: text, json, markdown');

        new ChatOptions(responseFormat: 'xml');
    }

    // Factory Presets

    #[Test]
    public function factualPresetHasLowTemperature(): void
    {
        $options = ChatOptions::factual();

        $this->assertEquals(0.2, $options->getTemperature());
        $this->assertEquals(0.9, $options->getTopP());
    }

    #[Test]
    public function creativePresetHasHighTemperature(): void
    {
        $options = ChatOptions::creative();

        $this->assertEquals(1.2, $options->getTemperature());
        $this->assertEquals(1.0, $options->getTopP());
        $this->assertEquals(0.6, $options->getPresencePenalty());
    }

    #[Test]
    public function balancedPresetHasMidTemperature(): void
    {
        $options = ChatOptions::balanced();

        $this->assertEquals(0.7, $options->getTemperature());
        $this->assertEquals(4096, $options->getMaxTokens());
    }

    #[Test]
    public function jsonPresetHasJsonResponseFormat(): void
    {
        $options = ChatOptions::json();

        $this->assertEquals(0.3, $options->getTemperature());
        $this->assertEquals('json', $options->getResponseFormat());
    }

    #[Test]
    public function codePresetIsOptimizedForCodeGeneration(): void
    {
        $options = ChatOptions::code();

        $this->assertEquals(0.2, $options->getTemperature());
        $this->assertEquals(8192, $options->getMaxTokens());
        $this->assertEquals(0.95, $options->getTopP());
        $this->assertEquals(0.0, $options->getFrequencyPenalty());
    }

    // Fluent Setters

    #[Test]
    public function withTemperatureReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(temperature: 0.5);
        $options2 = $options1->withTemperature(0.8);

        $this->assertNotSame($options1, $options2);
        $this->assertEquals(0.5, $options1->getTemperature());
        $this->assertEquals(0.8, $options2->getTemperature());
    }

    #[Test]
    public function withMaxTokensReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(maxTokens: 500);
        $options2 = $options1->withMaxTokens(1000);

        $this->assertEquals(500, $options1->getMaxTokens());
        $this->assertEquals(1000, $options2->getMaxTokens());
    }

    #[Test]
    public function withTopPReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(topP: 0.5);
        $options2 = $options1->withTopP(0.9);

        $this->assertEquals(0.5, $options1->getTopP());
        $this->assertEquals(0.9, $options2->getTopP());
    }

    #[Test]
    public function withFrequencyPenaltyReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(frequencyPenalty: 0.0);
        $options2 = $options1->withFrequencyPenalty(0.5);

        $this->assertEquals(0.0, $options1->getFrequencyPenalty());
        $this->assertEquals(0.5, $options2->getFrequencyPenalty());
    }

    #[Test]
    public function withPresencePenaltyReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(presencePenalty: 0.0);
        $options2 = $options1->withPresencePenalty(0.5);

        $this->assertEquals(0.0, $options1->getPresencePenalty());
        $this->assertEquals(0.5, $options2->getPresencePenalty());
    }

    #[Test]
    public function withResponseFormatReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(responseFormat: 'text');
        $options2 = $options1->withResponseFormat('json');

        $this->assertEquals('text', $options1->getResponseFormat());
        $this->assertEquals('json', $options2->getResponseFormat());
    }

    #[Test]
    public function withSystemPromptReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(systemPrompt: 'prompt1');
        $options2 = $options1->withSystemPrompt('prompt2');

        $this->assertEquals('prompt1', $options1->getSystemPrompt());
        $this->assertEquals('prompt2', $options2->getSystemPrompt());
    }

    #[Test]
    public function withStopSequencesReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(stopSequences: ['END']);
        $options2 = $options1->withStopSequences(['STOP', 'END']);

        $this->assertEquals(['END'], $options1->getStopSequences());
        $this->assertEquals(['STOP', 'END'], $options2->getStopSequences());
    }

    #[Test]
    public function withProviderReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(provider: 'openai');
        $options2 = $options1->withProvider('claude');

        $this->assertEquals('openai', $options1->getProvider());
        $this->assertEquals('claude', $options2->getProvider());
    }

    #[Test]
    public function withModelReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(model: 'gpt-4o');
        $options2 = $options1->withModel('gpt-4-turbo');

        $this->assertEquals('gpt-4o', $options1->getModel());
        $this->assertEquals('gpt-4-turbo', $options2->getModel());
    }

    #[Test]
    public function fluentSettersValidateValues(): void
    {
        $options = new ChatOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withTemperature(3.0);
    }

    // Array Conversion

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new ChatOptions(temperature: 0.7, maxTokens: 1000);

        $array = $options->toArray();

        $this->assertArrayHasKey('temperature', $array);
        $this->assertArrayHasKey('max_tokens', $array);
        $this->assertArrayNotHasKey('top_p', $array);
        $this->assertArrayNotHasKey('system_prompt', $array);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $options = new ChatOptions(
            maxTokens: 1000,
            topP: 0.9,
            frequencyPenalty: 0.5,
            presencePenalty: 0.5,
            responseFormat: 'json',
            systemPrompt: 'test',
            stopSequences: ['END'],
        );

        $array = $options->toArray();

        $this->assertArrayHasKey('max_tokens', $array);
        $this->assertArrayHasKey('top_p', $array);
        $this->assertArrayHasKey('frequency_penalty', $array);
        $this->assertArrayHasKey('presence_penalty', $array);
        $this->assertArrayHasKey('response_format', $array);
        $this->assertArrayHasKey('system_prompt', $array);
        $this->assertArrayHasKey('stop_sequences', $array);
    }

    #[Test]
    public function fromArrayCreatesOptionsCorrectly(): void
    {
        $array = [
            'temperature' => 0.8,
            'max_tokens' => 2000,
            'top_p' => 0.95,
            'frequency_penalty' => 0.2,
            'presence_penalty' => 0.3,
            'response_format' => 'markdown',
            'system_prompt' => 'Be helpful',
            'stop_sequences' => ['DONE'],
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
        ];

        $options = ChatOptions::fromArray($array);

        $this->assertEquals(0.8, $options->getTemperature());
        $this->assertEquals(2000, $options->getMaxTokens());
        $this->assertEquals(0.95, $options->getTopP());
        $this->assertEquals(0.2, $options->getFrequencyPenalty());
        $this->assertEquals(0.3, $options->getPresencePenalty());
        $this->assertEquals('markdown', $options->getResponseFormat());
        $this->assertEquals('Be helpful', $options->getSystemPrompt());
        $this->assertEquals(['DONE'], $options->getStopSequences());
        $this->assertEquals('claude', $options->getProvider());
        $this->assertEquals('claude-sonnet-4-20250514', $options->getModel());
    }

    #[Test]
    public function fromArrayHandlesMissingKeys(): void
    {
        $options = ChatOptions::fromArray([]);

        $this->assertNull($options->getTemperature());
        $this->assertNull($options->getMaxTokens());
        $this->assertNull($options->getProvider());
    }

    #[Test]
    public function mergeOverridesWithArray(): void
    {
        $options = new ChatOptions(temperature: 0.5, maxTokens: 1000);

        $merged = $options->merge(['temperature' => 0.9, 'model' => 'gpt-4']);

        $this->assertEquals(0.9, $merged['temperature']);
        $this->assertEquals(1000, $merged['max_tokens']);
        $this->assertEquals('gpt-4', $merged['model']);
    }

    #[Test]
    public function chainedFluentSettersWork(): void
    {
        $options = ChatOptions::factual()
            ->withMaxTokens(2000)
            ->withSystemPrompt('Be precise')
            ->withProvider('openai')
            ->withModel('gpt-4o');

        $this->assertEquals(0.2, $options->getTemperature());
        $this->assertEquals(2000, $options->getMaxTokens());
        $this->assertEquals('Be precise', $options->getSystemPrompt());
        $this->assertEquals('openai', $options->getProvider());
        $this->assertEquals('gpt-4o', $options->getModel());
    }
}
