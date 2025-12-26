<?php

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

        $this->assertEquals('more', $options->formality);
    }

    #[Test]
    public function informalFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::informal();

        $this->assertEquals('less', $options->formality);
    }

    #[Test]
    public function htmlFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::html();

        $this->assertEquals('html', $options->tagHandling);
        $this->assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function xmlFactoryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::xml();

        $this->assertEquals('xml', $options->tagHandling);
        $this->assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function withGlossaryCreatesCorrectOptions(): void
    {
        $options = DeepLOptions::withGlossary('glossary-123');

        $this->assertEquals('glossary-123', $options->glossaryId);
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

        $this->assertEquals('more', $options->formality);
        $this->assertEquals('glos-1', $options->glossaryId);
        $this->assertTrue($options->preserveFormatting);
        $this->assertFalse($options->splitSentences);
        $this->assertEquals('html', $options->tagHandling);
        $this->assertEquals(['script'], $options->ignoreTags);
        $this->assertEquals(['br'], $options->nonSplittingTags);
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

        $this->assertEquals('glos-2', $options->glossaryId);
        $this->assertTrue($options->preserveFormatting);
        $this->assertTrue($options->splitSentences);
        $this->assertEquals('xml', $options->tagHandling);
        $this->assertEquals(['style'], $options->ignoreTags);
        $this->assertEquals(['hr'], $options->nonSplittingTags);
    }

    #[Test]
    public function fromArrayPrefersSnakeCaseOverCamelCase(): void
    {
        $options = DeepLOptions::fromArray([
            'glossary_id' => 'snake',
            'glossaryId' => 'camel',
        ]);

        // Snake case should take precedence
        $this->assertEquals('snake', $options->glossaryId);
    }

    #[Test]
    public function toArrayExcludesNullValues(): void
    {
        $options = new DeepLOptions();

        $array = $options->toArray();

        // Default formality should be included
        $this->assertArrayHasKey('formality', $array);
        // Null values should be excluded
        $this->assertArrayNotHasKey('glossary_id', $array);
        $this->assertArrayNotHasKey('preserve_formatting', $array);
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

        $this->assertEquals('more', $array['formality']);
        $this->assertEquals('glos-1', $array['glossary_id']);
        $this->assertTrue($array['preserve_formatting']);
        $this->assertFalse($array['split_sentences']);
        $this->assertEquals('html', $array['tag_handling']);
        $this->assertEquals(['script'], $array['ignore_tags']);
        $this->assertEquals(['br'], $array['non_splitting_tags']);
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
            $this->assertEquals($formality, $options->formality);
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
            $this->assertEquals($handling, $options->tagHandling);
        }
    }

    #[Test]
    public function defaultFormalityIsDefault(): void
    {
        $options = new DeepLOptions();

        $this->assertEquals('default', $options->formality);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $options = DeepLOptions::fromArray([]);

        $this->assertNull($options->formality);
        $this->assertNull($options->glossaryId);
    }
}
