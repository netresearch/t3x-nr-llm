<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranslationOptions::class)]
class TranslationOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $glossary = ['Hello' => 'Hallo', 'World' => 'Welt'];
        $options = new TranslationOptions(
            formality: 'formal',
            domain: 'technical',
            glossary: $glossary,
            context: 'Technical documentation',
            preserveFormatting: true,
            temperature: 0.2,
            maxTokens: 1000,
            provider: 'deepl',
            model: 'deepl-pro',
        );

        self::assertEquals('formal', $options->getFormality());
        self::assertEquals('technical', $options->getDomain());
        self::assertEquals($glossary, $options->getGlossary());
        self::assertEquals('Technical documentation', $options->getContext());
        self::assertTrue($options->getPreserveFormatting());
        self::assertEquals(0.2, $options->getTemperature());
        self::assertEquals(1000, $options->getMaxTokens());
        self::assertEquals('deepl', $options->getProvider());
        self::assertEquals('deepl-pro', $options->getModel());
    }

    #[Test]
    public function constructorDefaultsPreserveFormattingToTrue(): void
    {
        $options = new TranslationOptions();

        self::assertTrue($options->getPreserveFormatting());
    }

    #[Test]
    #[DataProvider('validFormalityProvider')]
    public function constructorAcceptsValidFormality(string $formality): void
    {
        $options = new TranslationOptions(formality: $formality);

        self::assertEquals($formality, $options->getFormality());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFormalityProvider(): array
    {
        return [
            'default' => ['default'],
            'formal' => ['formal'],
            'informal' => ['informal'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFormalityProvider')]
    public function constructorThrowsForInvalidFormality(string $formality): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('formality must be one of: default, formal, informal');

        new TranslationOptions(formality: $formality);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFormalityProvider(): array
    {
        return [
            'casual' => ['casual'],
            'professional' => ['professional'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('validDomainProvider')]
    public function constructorAcceptsValidDomain(string $domain): void
    {
        $options = new TranslationOptions(domain: $domain);

        self::assertEquals($domain, $options->getDomain());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDomainProvider(): array
    {
        return [
            'general' => ['general'],
            'technical' => ['technical'],
            'medical' => ['medical'],
            'legal' => ['legal'],
            'marketing' => ['marketing'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDomainProvider')]
    public function constructorThrowsForInvalidDomain(string $domain): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('domain must be one of: general, technical, medical, legal, marketing');

        new TranslationOptions(domain: $domain);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDomainProvider(): array
    {
        return [
            'finance' => ['finance'],
            'academic' => ['academic'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidTemperature(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        new TranslationOptions(temperature: 2.5);
    }

    #[Test]
    public function constructorThrowsForNegativeMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be a positive integer');

        new TranslationOptions(maxTokens: 0);
    }

    // Factory Presets

    #[Test]
    public function formalPresetHasFormalFormality(): void
    {
        $options = TranslationOptions::formal();

        self::assertEquals('formal', $options->getFormality());
        self::assertEquals('general', $options->getDomain());
        self::assertEquals(0.2, $options->getTemperature());
    }

    #[Test]
    public function informalPresetHasInformalFormality(): void
    {
        $options = TranslationOptions::informal();

        self::assertEquals('informal', $options->getFormality());
        self::assertEquals('general', $options->getDomain());
        self::assertEquals(0.5, $options->getTemperature());
    }

    #[Test]
    public function technicalPresetIsOptimizedForDocs(): void
    {
        $options = TranslationOptions::technical();

        self::assertEquals('formal', $options->getFormality());
        self::assertEquals('technical', $options->getDomain());
        self::assertTrue($options->getPreserveFormatting());
        self::assertEquals(0.1, $options->getTemperature());
    }

    #[Test]
    public function marketingPresetIsCreative(): void
    {
        $options = TranslationOptions::marketing();

        self::assertEquals('default', $options->getFormality());
        self::assertEquals('marketing', $options->getDomain());
        self::assertEquals(0.6, $options->getTemperature());
    }

    #[Test]
    public function medicalPresetIsOptimizedForAccuracy(): void
    {
        $options = TranslationOptions::medical();

        self::assertEquals('formal', $options->getFormality());
        self::assertEquals('medical', $options->getDomain());
        self::assertTrue($options->getPreserveFormatting());
        self::assertEquals(0.1, $options->getTemperature());
    }

    #[Test]
    public function legalPresetIsOptimizedForAccuracy(): void
    {
        $options = TranslationOptions::legal();

        self::assertEquals('formal', $options->getFormality());
        self::assertEquals('legal', $options->getDomain());
        self::assertTrue($options->getPreserveFormatting());
        self::assertEquals(0.1, $options->getTemperature());
    }

    // Fluent Setters

    #[Test]
    public function withFormalityReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(formality: 'formal');
        $options2 = $options1->withFormality('informal');

        self::assertNotSame($options1, $options2);
        self::assertEquals('formal', $options1->getFormality());
        self::assertEquals('informal', $options2->getFormality());
    }

    #[Test]
    public function withFormalityValidatesValue(): void
    {
        $options = new TranslationOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withFormality('invalid');
    }

    #[Test]
    public function withDomainReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(domain: 'general');
        $options2 = $options1->withDomain('technical');

        self::assertEquals('general', $options1->getDomain());
        self::assertEquals('technical', $options2->getDomain());
    }

    #[Test]
    public function withDomainValidatesValue(): void
    {
        $options = new TranslationOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withDomain('invalid');
    }

    #[Test]
    public function withGlossaryReturnsNewInstance(): void
    {
        $glossary1 = ['Hello' => 'Hallo'];
        $glossary2 = ['World' => 'Welt'];

        $options1 = new TranslationOptions(glossary: $glossary1);
        $options2 = $options1->withGlossary($glossary2);

        self::assertEquals($glossary1, $options1->getGlossary());
        self::assertEquals($glossary2, $options2->getGlossary());
    }

    #[Test]
    public function withContextReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(context: 'context1');
        $options2 = $options1->withContext('context2');

        self::assertEquals('context1', $options1->getContext());
        self::assertEquals('context2', $options2->getContext());
    }

    #[Test]
    public function withPreserveFormattingReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(preserveFormatting: true);
        $options2 = $options1->withPreserveFormatting(false);

        self::assertTrue($options1->getPreserveFormatting());
        self::assertFalse($options2->getPreserveFormatting());
    }

    #[Test]
    public function withTemperatureReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(temperature: 0.2);
        $options2 = $options1->withTemperature(0.5);

        self::assertEquals(0.2, $options1->getTemperature());
        self::assertEquals(0.5, $options2->getTemperature());
    }

    #[Test]
    public function withTemperatureValidatesValue(): void
    {
        $options = new TranslationOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withTemperature(3.0);
    }

    #[Test]
    public function withMaxTokensReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(maxTokens: 500);
        $options2 = $options1->withMaxTokens(1000);

        self::assertEquals(500, $options1->getMaxTokens());
        self::assertEquals(1000, $options2->getMaxTokens());
    }

    #[Test]
    public function withMaxTokensValidatesValue(): void
    {
        $options = new TranslationOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withMaxTokens(0);
    }

    #[Test]
    public function withProviderReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(provider: 'deepl');
        $options2 = $options1->withProvider('openai');

        self::assertEquals('deepl', $options1->getProvider());
        self::assertEquals('openai', $options2->getProvider());
    }

    #[Test]
    public function withModelReturnsNewInstance(): void
    {
        $options1 = new TranslationOptions(model: 'model1');
        $options2 = $options1->withModel('model2');

        self::assertEquals('model1', $options1->getModel());
        self::assertEquals('model2', $options2->getModel());
    }

    // Array Conversion

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new TranslationOptions(formality: 'formal');

        $array = $options->toArray();

        self::assertArrayHasKey('formality', $array);
        self::assertArrayHasKey('preserve_formatting', $array);
        self::assertArrayNotHasKey('domain', $array);
        self::assertArrayNotHasKey('glossary', $array);
        self::assertArrayNotHasKey('context', $array);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $options = new TranslationOptions(
            formality: 'formal',
            preserveFormatting: true,
            maxTokens: 1000,
        );

        $array = $options->toArray();

        self::assertArrayHasKey('preserve_formatting', $array);
        self::assertArrayHasKey('max_tokens', $array);
    }

    #[Test]
    public function toArrayIncludesGlossary(): void
    {
        $glossary = ['Hello' => 'Hallo', 'World' => 'Welt'];
        $options = new TranslationOptions(glossary: $glossary);

        $array = $options->toArray();

        self::assertArrayHasKey('glossary', $array);
        self::assertEquals($glossary, $array['glossary']);
    }

    #[Test]
    public function chainedFluentSettersWork(): void
    {
        $glossary = ['test' => 'Test'];
        $options = TranslationOptions::technical()
            ->withFormality('informal')
            ->withDomain('marketing')
            ->withGlossary($glossary)
            ->withContext('Marketing campaign')
            ->withPreserveFormatting(false)
            ->withTemperature(0.5)
            ->withMaxTokens(2000)
            ->withProvider('openai')
            ->withModel('gpt-4o');

        self::assertEquals('informal', $options->getFormality());
        self::assertEquals('marketing', $options->getDomain());
        self::assertEquals($glossary, $options->getGlossary());
        self::assertEquals('Marketing campaign', $options->getContext());
        self::assertFalse($options->getPreserveFormatting());
        self::assertEquals(0.5, $options->getTemperature());
        self::assertEquals(2000, $options->getMaxTokens());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4o', $options->getModel());
    }
}
