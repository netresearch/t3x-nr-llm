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

        self::assertEquals(0.7, $options->getTemperature());
        self::assertEquals(1000, $options->getMaxTokens());
        self::assertEquals(0.9, $options->getTopP());
        self::assertEquals(0.5, $options->getFrequencyPenalty());
        self::assertEquals(0.5, $options->getPresencePenalty());
        self::assertEquals('json', $options->getResponseFormat());
        self::assertEquals('You are helpful.', $options->getSystemPrompt());
        self::assertEquals(['END'], $options->getStopSequences());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4o', $options->getModel());
    }

    #[Test]
    #[DataProvider('invalidTemperatureProvider')]
    public function constructorThrowsForInvalidTemperature(float $temperature): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        new ChatOptions(temperature: $temperature);
    }

    /**
     * @return array<string, array{float}>
     */
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

        self::assertEquals($temperature, $options->getTemperature());
    }

    /**
     * @return array<string, array{float}>
     */
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

    /**
     * @return array<string, array{float}>
     */
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

    /**
     * @return array<string, array{float}>
     */
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

        self::assertEquals(0.2, $options->getTemperature());
        self::assertEquals(0.9, $options->getTopP());
    }

    #[Test]
    public function creativePresetHasHighTemperature(): void
    {
        $options = ChatOptions::creative();

        self::assertEquals(1.2, $options->getTemperature());
        self::assertEquals(1.0, $options->getTopP());
        self::assertEquals(0.6, $options->getPresencePenalty());
    }

    #[Test]
    public function balancedPresetHasMidTemperature(): void
    {
        $options = ChatOptions::balanced();

        self::assertEquals(0.7, $options->getTemperature());
        self::assertEquals(4096, $options->getMaxTokens());
    }

    #[Test]
    public function jsonPresetHasJsonResponseFormat(): void
    {
        $options = ChatOptions::json();

        self::assertEquals(0.3, $options->getTemperature());
        self::assertEquals('json', $options->getResponseFormat());
    }

    #[Test]
    public function codePresetIsOptimizedForCodeGeneration(): void
    {
        $options = ChatOptions::code();

        self::assertEquals(0.2, $options->getTemperature());
        self::assertEquals(8192, $options->getMaxTokens());
        self::assertEquals(0.95, $options->getTopP());
        self::assertEquals(0.0, $options->getFrequencyPenalty());
    }

    // Fluent Setters

    #[Test]
    public function withTemperatureReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(temperature: 0.5);
        $options2 = $options1->withTemperature(0.8);

        self::assertNotSame($options1, $options2);
        self::assertEquals(0.5, $options1->getTemperature());
        self::assertEquals(0.8, $options2->getTemperature());
    }

    #[Test]
    public function withMaxTokensReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(maxTokens: 500);
        $options2 = $options1->withMaxTokens(1000);

        self::assertEquals(500, $options1->getMaxTokens());
        self::assertEquals(1000, $options2->getMaxTokens());
    }

    #[Test]
    public function withTopPReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(topP: 0.5);
        $options2 = $options1->withTopP(0.9);

        self::assertEquals(0.5, $options1->getTopP());
        self::assertEquals(0.9, $options2->getTopP());
    }

    #[Test]
    public function withFrequencyPenaltyReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(frequencyPenalty: 0.0);
        $options2 = $options1->withFrequencyPenalty(0.5);

        self::assertEquals(0.0, $options1->getFrequencyPenalty());
        self::assertEquals(0.5, $options2->getFrequencyPenalty());
    }

    #[Test]
    public function withPresencePenaltyReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(presencePenalty: 0.0);
        $options2 = $options1->withPresencePenalty(0.5);

        self::assertEquals(0.0, $options1->getPresencePenalty());
        self::assertEquals(0.5, $options2->getPresencePenalty());
    }

    #[Test]
    public function withResponseFormatReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(responseFormat: 'text');
        $options2 = $options1->withResponseFormat('json');

        self::assertEquals('text', $options1->getResponseFormat());
        self::assertEquals('json', $options2->getResponseFormat());
    }

    #[Test]
    public function withSystemPromptReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(systemPrompt: 'prompt1');
        $options2 = $options1->withSystemPrompt('prompt2');

        self::assertEquals('prompt1', $options1->getSystemPrompt());
        self::assertEquals('prompt2', $options2->getSystemPrompt());
    }

    #[Test]
    public function withStopSequencesReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(stopSequences: ['END']);
        $options2 = $options1->withStopSequences(['STOP', 'END']);

        self::assertEquals(['END'], $options1->getStopSequences());
        self::assertEquals(['STOP', 'END'], $options2->getStopSequences());
    }

    #[Test]
    public function withProviderReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(provider: 'openai');
        $options2 = $options1->withProvider('claude');

        self::assertEquals('openai', $options1->getProvider());
        self::assertEquals('claude', $options2->getProvider());
    }

    #[Test]
    public function withModelReturnsNewInstance(): void
    {
        $options1 = new ChatOptions(model: 'gpt-4o');
        $options2 = $options1->withModel('gpt-4-turbo');

        self::assertEquals('gpt-4o', $options1->getModel());
        self::assertEquals('gpt-4-turbo', $options2->getModel());
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

        self::assertArrayHasKey('temperature', $array);
        self::assertArrayHasKey('max_tokens', $array);
        self::assertArrayNotHasKey('top_p', $array);
        self::assertArrayNotHasKey('system_prompt', $array);
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

        self::assertArrayHasKey('max_tokens', $array);
        self::assertArrayHasKey('top_p', $array);
        self::assertArrayHasKey('frequency_penalty', $array);
        self::assertArrayHasKey('presence_penalty', $array);
        self::assertArrayHasKey('response_format', $array);
        self::assertArrayHasKey('system_prompt', $array);
        self::assertArrayHasKey('stop_sequences', $array);
    }

    #[Test]
    public function chainedFluentSettersWork(): void
    {
        $options = ChatOptions::factual()
            ->withMaxTokens(2000)
            ->withSystemPrompt('Be precise')
            ->withProvider('openai')
            ->withModel('gpt-4o');

        self::assertEquals(0.2, $options->getTemperature());
        self::assertEquals(2000, $options->getMaxTokens());
        self::assertEquals('Be precise', $options->getSystemPrompt());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4o', $options->getModel());
    }
}
