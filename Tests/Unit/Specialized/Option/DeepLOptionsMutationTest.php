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
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for DeepLOptions.
 */
#[CoversClass(DeepLOptions::class)]
class DeepLOptionsMutationTest extends AbstractUnitTestCase
{
    #[Test]
    public function formalFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::formal();

        self::assertEquals('more', $options->formality);
    }

    #[Test]
    public function informalFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::informal();

        self::assertEquals('less', $options->formality);
    }

    #[Test]
    public function htmlFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::html();

        self::assertEquals('html', $options->tagHandling);
        self::assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function xmlFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::xml();

        self::assertEquals('xml', $options->tagHandling);
        self::assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function withGlossaryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::withGlossary('glossary-123');

        self::assertEquals('glossary-123', $options->glossaryId);
    }

    #[Test]
    public function fromArrayWithSnakeCaseKeys(): void
    {
        $options = DeepLOptions::fromArray([
            'formality' => 'more',
            'glossary_id' => 'glos-1',
            'preserve_formatting' => true,
            'split_sentences' => false,
            'tag_handling' => 'html',
            'ignore_tags' => ['script'],
            'non_splitting_tags' => ['br'],
        ]);

        self::assertEquals('more', $options->formality);
        self::assertEquals('glos-1', $options->glossaryId);
        self::assertTrue($options->preserveFormatting);
        self::assertFalse($options->splitSentences);
        self::assertEquals('html', $options->tagHandling);
        self::assertEquals(['script'], $options->ignoreTags);
        self::assertEquals(['br'], $options->nonSplittingTags);
    }

    #[Test]
    public function fromArrayWithCamelCaseKeys(): void
    {
        $options = DeepLOptions::fromArray([
            'glossaryId' => 'glos-2',
            'preserveFormatting' => true,
            'splitSentences' => true,
            'tagHandling' => 'xml',
            'ignoreTags' => ['style'],
            'nonSplittingTags' => ['hr'],
        ]);

        self::assertEquals('glos-2', $options->glossaryId);
        self::assertTrue($options->preserveFormatting);
        self::assertTrue($options->splitSentences);
        self::assertEquals('xml', $options->tagHandling);
        self::assertEquals(['style'], $options->ignoreTags);
        self::assertEquals(['hr'], $options->nonSplittingTags);
    }

    #[Test]
    public function fromArrayPrefersSnakeCaseOverCamelCase(): void
    {
        $options = DeepLOptions::fromArray([
            'glossary_id' => 'snake',
            'glossaryId' => 'camel',
        ]);

        // Snake case should take precedence
        self::assertEquals('snake', $options->glossaryId);
    }

    #[Test]
    public function toArrayExcludesNullValues(): void
    {
        $options = new DeepLOptions();

        $array = $options->toArray();

        // Default formality should be included
        self::assertArrayHasKey('formality', $array);
        // Null values should be excluded
        self::assertArrayNotHasKey('glossary_id', $array);
        self::assertArrayNotHasKey('preserve_formatting', $array);
    }

    #[Test]
    public function toArrayIncludesAllSetValues(): void
    {
        $options = new DeepLOptions(
            formality: 'more',
            glossaryId: 'glos-1',
            preserveFormatting: true,
            splitSentences: false,
            tagHandling: 'html',
            ignoreTags: ['script'],
            nonSplittingTags: ['br'],
        );

        $array = $options->toArray();

        self::assertEquals('more', $array['formality']);
        self::assertEquals('glos-1', $array['glossary_id']);
        self::assertTrue($array['preserve_formatting']);
        self::assertFalse($array['split_sentences']);
        self::assertEquals('html', $array['tag_handling']);
        self::assertEquals(['script'], $array['ignore_tags']);
        self::assertEquals(['br'], $array['non_splitting_tags']);
    }

    #[Test]
    public function validateRejectsInvalidFormality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('formality');

        new DeepLOptions(formality: 'invalid');
    }

    #[Test]
    public function validateAcceptsAllValidFormalities(): void
    {
        $validFormalities = ['default', 'more', 'less', 'prefer_more', 'prefer_less'];

        foreach ($validFormalities as $formality) {
            $options = new DeepLOptions(formality: $formality);
            self::assertEquals($formality, $options->formality);
        }
    }

    #[Test]
    public function validateRejectsInvalidTagHandling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tagHandling');

        new DeepLOptions(tagHandling: 'invalid');
    }

    #[Test]
    public function validateAcceptsAllValidTagHandling(): void
    {
        $validHandlings = ['xml', 'html'];

        foreach ($validHandlings as $handling) {
            $options = new DeepLOptions(tagHandling: $handling);
            self::assertEquals($handling, $options->tagHandling);
        }
    }

    #[Test]
    public function defaultFormalityIsDefault(): void
    {
        $options = new DeepLOptions();

        self::assertEquals('default', $options->formality);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $options = DeepLOptions::fromArray([]);

        self::assertNull($options->formality);
        self::assertNull($options->glossaryId);
    }
}
