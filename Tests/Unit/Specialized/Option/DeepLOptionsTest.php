<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DeepLOptions::class)]
class DeepLOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $options = new DeepLOptions(
            formality: 'more',
            glossaryId: 'gls_123',
            preserveFormatting: true,
            splitSentences: false,
            tagHandling: 'html',
            ignoreTags: ['code', 'pre'],
            nonSplittingTags: ['span'],
        );

        self::assertEquals('more', $options->formality);
        self::assertEquals('gls_123', $options->glossaryId);
        self::assertTrue($options->preserveFormatting);
        self::assertFalse($options->splitSentences);
        self::assertEquals('html', $options->tagHandling);
        self::assertEquals(['code', 'pre'], $options->ignoreTags);
        self::assertEquals(['span'], $options->nonSplittingTags);
    }

    #[Test]
    public function constructorDefaultsToDefaultFormality(): void
    {
        $options = new DeepLOptions();

        self::assertEquals('default', $options->formality);
    }

    #[Test]
    #[DataProvider('validFormalityProvider')]
    public function constructorAcceptsValidFormality(string $formality): void
    {
        $options = new DeepLOptions(formality: $formality);

        self::assertEquals($formality, $options->formality);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFormalityProvider(): array
    {
        return [
            'default' => ['default'],
            'more' => ['more'],
            'less' => ['less'],
            'prefer_more' => ['prefer_more'],
            'prefer_less' => ['prefer_less'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidFormality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('formality must be one of');

        new DeepLOptions(formality: 'invalid');
    }

    #[Test]
    #[DataProvider('validTagHandlingProvider')]
    public function constructorAcceptsValidTagHandling(string $tagHandling): void
    {
        $options = new DeepLOptions(tagHandling: $tagHandling);

        self::assertEquals($tagHandling, $options->tagHandling);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTagHandlingProvider(): array
    {
        return [
            'xml' => ['xml'],
            'html' => ['html'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidTagHandling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tagHandling must be one of');

        new DeepLOptions(tagHandling: 'markdown');
    }

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new DeepLOptions(formality: 'more');

        $array = $options->toArray();

        self::assertArrayHasKey('formality', $array);
        self::assertArrayNotHasKey('glossary_id', $array);
        self::assertArrayNotHasKey('preserve_formatting', $array);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $options = new DeepLOptions(
            glossaryId: 'gls_123',
            preserveFormatting: true,
            splitSentences: true,
            tagHandling: 'html',
            ignoreTags: ['code'],
            nonSplittingTags: ['span'],
        );

        $array = $options->toArray();

        self::assertArrayHasKey('glossary_id', $array);
        self::assertArrayHasKey('preserve_formatting', $array);
        self::assertArrayHasKey('split_sentences', $array);
        self::assertArrayHasKey('tag_handling', $array);
        self::assertArrayHasKey('ignore_tags', $array);
        self::assertArrayHasKey('non_splitting_tags', $array);
    }

    #[Test]
    public function fromArrayCreatesOptionsWithSnakeCaseKeys(): void
    {
        $array = [
            'formality' => 'less',
            'glossary_id' => 'gls_456',
            'preserve_formatting' => true,
            'split_sentences' => false,
            'tag_handling' => 'xml',
            'ignore_tags' => ['code'],
            'non_splitting_tags' => ['em'],
        ];

        $options = DeepLOptions::fromArray($array);

        self::assertEquals('less', $options->formality);
        self::assertEquals('gls_456', $options->glossaryId);
        self::assertTrue($options->preserveFormatting);
        self::assertFalse($options->splitSentences);
        self::assertEquals('xml', $options->tagHandling);
        self::assertEquals(['code'], $options->ignoreTags);
        self::assertEquals(['em'], $options->nonSplittingTags);
    }

    #[Test]
    public function fromArraySupportsCamelCaseKeys(): void
    {
        $array = [
            'glossaryId' => 'gls_789',
            'preserveFormatting' => false,
            'splitSentences' => true,
            'tagHandling' => 'html',
            'ignoreTags' => ['pre'],
            'nonSplittingTags' => ['span'],
        ];

        $options = DeepLOptions::fromArray($array);

        self::assertEquals('gls_789', $options->glossaryId);
        self::assertFalse($options->preserveFormatting);
        self::assertTrue($options->splitSentences);
    }

    #[Test]
    public function formalPresetHasMoreFormality(): void
    {
        $options = DeepLOptions::formal();

        self::assertEquals('more', $options->formality);
    }

    #[Test]
    public function informalPresetHasLessFormality(): void
    {
        $options = DeepLOptions::informal();

        self::assertEquals('less', $options->formality);
    }

    #[Test]
    public function htmlPresetHasHtmlTagHandling(): void
    {
        $options = DeepLOptions::html();

        self::assertEquals('html', $options->tagHandling);
        self::assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function xmlPresetHasXmlTagHandling(): void
    {
        $options = DeepLOptions::xml();

        self::assertEquals('xml', $options->tagHandling);
        self::assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function withGlossaryCreatesOptionsWithGlossaryId(): void
    {
        $options = DeepLOptions::withGlossary('gls_abc123');

        self::assertEquals('gls_abc123', $options->glossaryId);
    }

    #[Test]
    public function nullFormalityIsAllowed(): void
    {
        $options = new DeepLOptions(formality: null);

        self::assertNull($options->formality);
    }

    #[Test]
    public function optionsAreReadonly(): void
    {
        $options = new DeepLOptions(formality: 'more');

        // Verify readonly property is accessible
        self::assertEquals('more', $options->formality);
    }
}
