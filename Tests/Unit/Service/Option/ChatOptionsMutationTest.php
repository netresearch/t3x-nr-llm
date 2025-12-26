<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for ChatOptions.
 */
#[CoversClass(ChatOptions::class)]
class ChatOptionsMutationTest extends AbstractUnitTestCase
{
    #[Test]
    public function factualPresetHasCorrectTemperature(): void
    {
        $options = ChatOptions::factual();

        $this->assertEquals(0.2, $options->getTemperature());
    }

    #[Test]
    public function factualPresetHasCorrectTopP(): void
    {
        $options = ChatOptions::factual();

        $this->assertEquals(0.9, $options->getTopP());
    }

    #[Test]
    public function creativePresetHasCorrectTemperature(): void
    {
        $options = ChatOptions::creative();

        $this->assertEquals(1.2, $options->getTemperature());
    }

    #[Test]
    public function creativePresetHasCorrectTopP(): void
    {
        $options = ChatOptions::creative();

        $this->assertEquals(1.0, $options->getTopP());
    }

    #[Test]
    public function creativePresetHasCorrectPresencePenalty(): void
    {
        $options = ChatOptions::creative();

        $this->assertEquals(0.6, $options->getPresencePenalty());
    }

    #[Test]
    public function balancedPresetHasCorrectTemperature(): void
    {
        $options = ChatOptions::balanced();

        $this->assertEquals(0.7, $options->getTemperature());
    }

    #[Test]
    public function balancedPresetHasCorrectMaxTokens(): void
    {
        $options = ChatOptions::balanced();

        $this->assertEquals(4096, $options->getMaxTokens());
    }

    #[Test]
    public function jsonPresetHasCorrectTemperature(): void
    {
        $options = ChatOptions::json();

        $this->assertEquals(0.3, $options->getTemperature());
    }

    #[Test]
    public function jsonPresetHasCorrectResponseFormat(): void
    {
        $options = ChatOptions::json();

        $this->assertEquals('json', $options->getResponseFormat());
    }

    #[Test]
    public function codePresetHasCorrectTemperature(): void
    {
        $options = ChatOptions::code();

        $this->assertEquals(0.2, $options->getTemperature());
    }

    #[Test]
    public function codePresetHasCorrectMaxTokens(): void
    {
        $options = ChatOptions::code();

        $this->assertEquals(8192, $options->getMaxTokens());
    }

    #[Test]
    public function codePresetHasCorrectTopP(): void
    {
        $options = ChatOptions::code();

        $this->assertEquals(0.95, $options->getTopP());
    }

    #[Test]
    public function codePresetHasCorrectFrequencyPenalty(): void
    {
        $options = ChatOptions::code();

        $this->assertEquals(0.0, $options->getFrequencyPenalty());
    }

    #[Test]
    public function withTemperatureReturnsNewInstance(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withTemperature(0.5);

        $this->assertNotSame($options, $newOptions);
        $this->assertNull($options->getTemperature());
        $this->assertEquals(0.5, $newOptions->getTemperature());
    }

    #[Test]
    public function withMaxTokensReturnsNewInstance(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withMaxTokens(2048);

        $this->assertNotSame($options, $newOptions);
        $this->assertNull($options->getMaxTokens());
        $this->assertEquals(2048, $newOptions->getMaxTokens());
    }

    #[Test]
    public function toArrayExcludesNullValues(): void
    {
        $options = new ChatOptions(temperature: 0.5);

        $array = $options->toArray();

        $this->assertArrayHasKey('temperature', $array);
        $this->assertArrayNotHasKey('max_tokens', $array);
        $this->assertArrayNotHasKey('top_p', $array);
    }

    #[Test]
    public function toArrayIncludesAllSetValues(): void
    {
        $options = new ChatOptions(
            temperature: 0.5,
            maxTokens: 1024,
            topP: 0.9,
            frequencyPenalty: 0.1,
            presencePenalty: 0.2,
            responseFormat: 'json',
            systemPrompt: 'Be helpful',
            stopSequences: ['END'],
            provider: 'openai',
            model: 'gpt-5.2',
        );

        $array = $options->toArray();

        $this->assertEquals(0.5, $array['temperature']);
        $this->assertEquals(1024, $array['max_tokens']);
        $this->assertEquals(0.9, $array['top_p']);
        $this->assertEquals(0.1, $array['frequency_penalty']);
        $this->assertEquals(0.2, $array['presence_penalty']);
        $this->assertEquals('json', $array['response_format']);
        $this->assertEquals('Be helpful', $array['system_prompt']);
        $this->assertEquals(['END'], $array['stop_sequences']);
        $this->assertEquals('openai', $array['provider']);
        $this->assertEquals('gpt-5.2', $array['model']);
    }

    #[Test]
    public function mergeOverridesExistingValues(): void
    {
        $options = new ChatOptions(temperature: 0.5, maxTokens: 1024);

        $merged = $options->merge(['temperature' => 0.8, 'new_key' => 'value']);

        $this->assertEquals(0.8, $merged['temperature']);
        $this->assertEquals(1024, $merged['max_tokens']);
        $this->assertEquals('value', $merged['new_key']);
    }

    #[Test]
    public function validateThrowsOnInvalidTemperature(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature');

        new ChatOptions(temperature: 3.0); // Above max of 2.0
    }

    #[Test]
    public function validateThrowsOnNegativeMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens');

        new ChatOptions(maxTokens: -1);
    }

    #[Test]
    public function validateThrowsOnInvalidTopP(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('top_p');

        new ChatOptions(topP: 1.5); // Above max of 1.0
    }

    #[Test]
    public function validateThrowsOnInvalidResponseFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response_format');

        new ChatOptions(responseFormat: 'invalid');
    }

    #[Test]
    public function withSystemPromptSetsValue(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withSystemPrompt('You are helpful');

        $this->assertEquals('You are helpful', $newOptions->getSystemPrompt());
    }

    #[Test]
    public function withStopSequencesSetsValue(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withStopSequences(['STOP', 'END']);

        $this->assertEquals(['STOP', 'END'], $newOptions->getStopSequences());
    }

    #[Test]
    public function withProviderSetsValue(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withProvider('claude');

        $this->assertEquals('claude', $newOptions->getProvider());
    }

    #[Test]
    public function withModelSetsValue(): void
    {
        $options = new ChatOptions();
        $newOptions = $options->withModel('gpt-5.2');

        $this->assertEquals('gpt-5.2', $newOptions->getModel());
    }

    #[Test]
    public function validateAllowsValidFrequencyPenalty(): void
    {
        // Should not throw
        $options = new ChatOptions(frequencyPenalty: -2.0);
        $this->assertEquals(-2.0, $options->getFrequencyPenalty());

        $options = new ChatOptions(frequencyPenalty: 2.0);
        $this->assertEquals(2.0, $options->getFrequencyPenalty());
    }

    #[Test]
    public function validateAllowsValidPresencePenalty(): void
    {
        // Should not throw
        $options = new ChatOptions(presencePenalty: -2.0);
        $this->assertEquals(-2.0, $options->getPresencePenalty());

        $options = new ChatOptions(presencePenalty: 2.0);
        $this->assertEquals(2.0, $options->getPresencePenalty());
    }

    #[Test]
    public function validateAllowsValidResponseFormats(): void
    {
        // All valid formats should work
        $textOptions = new ChatOptions(responseFormat: 'text');
        $this->assertEquals('text', $textOptions->getResponseFormat());

        $jsonOptions = new ChatOptions(responseFormat: 'json');
        $this->assertEquals('json', $jsonOptions->getResponseFormat());

        $mdOptions = new ChatOptions(responseFormat: 'markdown');
        $this->assertEquals('markdown', $mdOptions->getResponseFormat());
    }
}
