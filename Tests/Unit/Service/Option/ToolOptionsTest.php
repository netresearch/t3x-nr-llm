<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ToolOptions::class)]
class ToolOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $options = new ToolOptions(
            temperature: 0.7,
            maxTokens: 1000,
            topP: 0.9,
            toolChoice: 'auto',
            parallelToolCalls: true,
        );

        self::assertEquals(0.7, $options->getTemperature());
        self::assertEquals(1000, $options->getMaxTokens());
        self::assertEquals(0.9, $options->getTopP());
        self::assertEquals('auto', $options->getToolChoice());
        self::assertTrue($options->getParallelToolCalls());
    }

    #[Test]
    #[DataProvider('validToolChoiceProvider')]
    public function constructorAcceptsValidToolChoice(string $toolChoice): void
    {
        $options = new ToolOptions(toolChoice: $toolChoice);

        self::assertEquals($toolChoice, $options->getToolChoice());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validToolChoiceProvider(): array
    {
        return [
            'auto' => ['auto'],
            'none' => ['none'],
            'required' => ['required'],
        ];
    }

    #[Test]
    #[DataProvider('invalidToolChoiceProvider')]
    public function constructorThrowsForInvalidToolChoice(string $toolChoice): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tool_choice must be one of: auto, none, required');

        new ToolOptions(toolChoice: $toolChoice);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidToolChoiceProvider(): array
    {
        return [
            'manual' => ['manual'],
            'always' => ['always'],
            'empty' => [''],
        ];
    }

    // Factory Presets

    #[Test]
    public function autoPresetHasAutoToolChoice(): void
    {
        $options = ToolOptions::auto();

        self::assertEquals('auto', $options->getToolChoice());
        self::assertEquals(0.7, $options->getTemperature());
    }

    #[Test]
    public function requiredPresetForcesToolUsage(): void
    {
        $options = ToolOptions::required();

        self::assertEquals('required', $options->getToolChoice());
        self::assertEquals(0.3, $options->getTemperature());
    }

    #[Test]
    public function noToolsPresetDisablesTools(): void
    {
        $options = ToolOptions::noTools();

        self::assertEquals('none', $options->getToolChoice());
        self::assertEquals(0.7, $options->getTemperature());
    }

    #[Test]
    public function parallelPresetEnablesParallelCalls(): void
    {
        $options = ToolOptions::parallel();

        self::assertEquals('auto', $options->getToolChoice());
        self::assertTrue($options->getParallelToolCalls());
        self::assertEquals(0.7, $options->getTemperature());
    }

    // Fluent Setters

    #[Test]
    public function withToolChoiceReturnsNewInstance(): void
    {
        $options1 = new ToolOptions(toolChoice: 'auto');
        $options2 = $options1->withToolChoice('required');

        self::assertNotSame($options1, $options2);
        self::assertEquals('auto', $options1->getToolChoice());
        self::assertEquals('required', $options2->getToolChoice());
    }

    #[Test]
    public function withToolChoiceValidatesValue(): void
    {
        $options = new ToolOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withToolChoice('invalid');
    }

    #[Test]
    public function withParallelToolCallsReturnsNewInstance(): void
    {
        $options1 = new ToolOptions(parallelToolCalls: false);
        $options2 = $options1->withParallelToolCalls(true);

        self::assertNotSame($options1, $options2);
        self::assertFalse($options1->getParallelToolCalls());
        self::assertTrue($options2->getParallelToolCalls());
    }

    // Inheritance from ChatOptions

    #[Test]
    public function inheritsChatOptionsValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        new ToolOptions(temperature: 3.0);
    }

    #[Test]
    public function inheritsChatOptionsFluentSetters(): void
    {
        $options = ToolOptions::auto()
            ->withTemperature(0.5)
            ->withMaxTokens(2000)
            ->withSystemPrompt('Use tools wisely');

        self::assertEquals(0.5, $options->getTemperature());
        self::assertEquals(2000, $options->getMaxTokens());
        self::assertEquals('Use tools wisely', $options->getSystemPrompt());
    }

    // Array Conversion

    #[Test]
    public function toArrayIncludesToolOptionsAndParentOptions(): void
    {
        $options = new ToolOptions(
            temperature: 0.7,
            maxTokens: 1000,
            toolChoice: 'auto',
            parallelToolCalls: true,
        );

        $array = $options->toArray();

        // Tool-specific options
        self::assertArrayHasKey('tool_choice', $array);
        self::assertArrayHasKey('parallel_tool_calls', $array);
        self::assertEquals('auto', $array['tool_choice']);
        self::assertTrue($array['parallel_tool_calls']);

        // Parent ChatOptions
        self::assertArrayHasKey('temperature', $array);
        self::assertArrayHasKey('max_tokens', $array);
    }

    #[Test]
    public function toArrayFiltersNullToolOptions(): void
    {
        $options = new ToolOptions(temperature: 0.7);

        $array = $options->toArray();

        self::assertArrayHasKey('temperature', $array);
        self::assertArrayNotHasKey('tool_choice', $array);
        self::assertArrayNotHasKey('parallel_tool_calls', $array);
    }

    #[Test]
    public function chainedSettersWorkWithToolAndChatOptions(): void
    {
        $options = ToolOptions::parallel()
            ->withToolChoice('required')
            ->withParallelToolCalls(false)
            ->withTemperature(0.3)
            ->withMaxTokens(4000)
            ->withProvider('openai')
            ->withModel('gpt-4o');

        self::assertEquals('required', $options->getToolChoice());
        self::assertFalse($options->getParallelToolCalls());
        self::assertEquals(0.3, $options->getTemperature());
        self::assertEquals(4000, $options->getMaxTokens());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4o', $options->getModel());
    }
}
