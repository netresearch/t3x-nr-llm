<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * @covers \Netresearch\NrLlm\Domain\Model\AdapterType
 */
class AdapterTypeTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('adapterTypeValuesProvider')]
    public function enumHasCorrectValue(AdapterType $type, string $expectedValue): void
    {
        self::assertEquals($expectedValue, $type->value);
    }

    /**
     * @return array<string, array{AdapterType, string}>
     */
    public static function adapterTypeValuesProvider(): array
    {
        return [
            'OpenAI' => [AdapterType::OpenAI, 'openai'],
            'Anthropic' => [AdapterType::Anthropic, 'anthropic'],
            'Gemini' => [AdapterType::Gemini, 'gemini'],
            'OpenRouter' => [AdapterType::OpenRouter, 'openrouter'],
            'Mistral' => [AdapterType::Mistral, 'mistral'],
            'Groq' => [AdapterType::Groq, 'groq'],
            'Ollama' => [AdapterType::Ollama, 'ollama'],
            'AzureOpenAI' => [AdapterType::AzureOpenAI, 'azure_openai'],
            'Custom' => [AdapterType::Custom, 'custom'],
        ];
    }

    #[Test]
    #[DataProvider('adapterTypeLabelProvider')]
    public function labelReturnsHumanReadableName(AdapterType $type, string $expectedLabel): void
    {
        self::assertEquals($expectedLabel, $type->label());
    }

    /**
     * @return array<string, array{AdapterType, string}>
     */
    public static function adapterTypeLabelProvider(): array
    {
        return [
            'OpenAI' => [AdapterType::OpenAI, 'OpenAI'],
            'Anthropic' => [AdapterType::Anthropic, 'Anthropic (Claude)'],
            'Gemini' => [AdapterType::Gemini, 'Google Gemini'],
            'OpenRouter' => [AdapterType::OpenRouter, 'OpenRouter'],
            'Mistral' => [AdapterType::Mistral, 'Mistral AI'],
            'Groq' => [AdapterType::Groq, 'Groq'],
            'Ollama' => [AdapterType::Ollama, 'Ollama (Local)'],
            'AzureOpenAI' => [AdapterType::AzureOpenAI, 'Azure OpenAI'],
            'Custom' => [AdapterType::Custom, 'Custom (OpenAI-compatible)'],
        ];
    }

    #[Test]
    #[DataProvider('adapterTypeEndpointProvider')]
    public function defaultEndpointReturnsCorrectUrl(AdapterType $type, string $expectedEndpoint): void
    {
        self::assertEquals($expectedEndpoint, $type->defaultEndpoint());
    }

    /**
     * @return array<string, array{AdapterType, string}>
     */
    public static function adapterTypeEndpointProvider(): array
    {
        return [
            'OpenAI' => [AdapterType::OpenAI, 'https://api.openai.com/v1'],
            'Anthropic' => [AdapterType::Anthropic, 'https://api.anthropic.com/v1'],
            'Gemini' => [AdapterType::Gemini, 'https://generativelanguage.googleapis.com/v1beta'],
            'OpenRouter' => [AdapterType::OpenRouter, 'https://openrouter.ai/api/v1'],
            'Mistral' => [AdapterType::Mistral, 'https://api.mistral.ai/v1'],
            'Groq' => [AdapterType::Groq, 'https://api.groq.com/openai/v1'],
            'Ollama' => [AdapterType::Ollama, 'http://localhost:11434/api'],
            'AzureOpenAI' => [AdapterType::AzureOpenAI, ''],
            'Custom' => [AdapterType::Custom, ''],
        ];
    }

    #[Test]
    #[DataProvider('adapterTypeRequiresApiKeyProvider')]
    public function requiresApiKeyReturnsCorrectValue(AdapterType $type, bool $expectedValue): void
    {
        self::assertEquals($expectedValue, $type->requiresApiKey());
    }

    /**
     * @return array<string, array{AdapterType, bool}>
     */
    public static function adapterTypeRequiresApiKeyProvider(): array
    {
        return [
            'OpenAI requires key' => [AdapterType::OpenAI, true],
            'Anthropic requires key' => [AdapterType::Anthropic, true],
            'Gemini requires key' => [AdapterType::Gemini, true],
            'OpenRouter requires key' => [AdapterType::OpenRouter, true],
            'Mistral requires key' => [AdapterType::Mistral, true],
            'Groq requires key' => [AdapterType::Groq, true],
            'Ollama does not require key' => [AdapterType::Ollama, false],
            'AzureOpenAI requires key' => [AdapterType::AzureOpenAI, true],
            'Custom requires key' => [AdapterType::Custom, true],
        ];
    }

    #[Test]
    public function toSelectArrayReturnsAllAdapterTypes(): void
    {
        $result = AdapterType::toSelectArray();

        self::assertCount(9, $result);
        self::assertArrayHasKey('openai', $result);
        self::assertArrayHasKey('anthropic', $result);
        self::assertArrayHasKey('gemini', $result);
        self::assertArrayHasKey('openrouter', $result);
        self::assertArrayHasKey('mistral', $result);
        self::assertArrayHasKey('groq', $result);
        self::assertArrayHasKey('ollama', $result);
        self::assertArrayHasKey('azure_openai', $result);
        self::assertArrayHasKey('custom', $result);
    }

    #[Test]
    public function toSelectArrayContainsCorrectLabels(): void
    {
        $result = AdapterType::toSelectArray();

        self::assertEquals('OpenAI', $result['openai']);
        self::assertEquals('Anthropic (Claude)', $result['anthropic']);
        self::assertEquals('Google Gemini', $result['gemini']);
        self::assertEquals('Ollama (Local)', $result['ollama']);
    }

    #[Test]
    public function enumCanBeCreatedFromString(): void
    {
        $type = AdapterType::from('openai');

        self::assertSame(AdapterType::OpenAI, $type);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $invalidValue = 'invalid';
        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertNull(AdapterType::tryFrom($invalidValue));
    }

    #[Test]
    public function casesReturnsAllEnumCases(): void
    {
        $cases = AdapterType::cases();

        self::assertCount(9, $cases);
        self::assertContains(AdapterType::OpenAI, $cases);
        self::assertContains(AdapterType::Anthropic, $cases);
        self::assertContains(AdapterType::Gemini, $cases);
        self::assertContains(AdapterType::OpenRouter, $cases);
        self::assertContains(AdapterType::Mistral, $cases);
        self::assertContains(AdapterType::Groq, $cases);
        self::assertContains(AdapterType::Ollama, $cases);
        self::assertContains(AdapterType::AzureOpenAI, $cases);
        self::assertContains(AdapterType::Custom, $cases);
    }
}
