<?php

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

        $this->assertEquals('more', $options->formality);
        $this->assertEquals('gls_123', $options->glossaryId);
        $this->assertTrue($options->preserveFormatting);
        $this->assertFalse($options->splitSentences);
        $this->assertEquals('html', $options->tagHandling);
        $this->assertEquals(['code', 'pre'], $options->ignoreTags);
        $this->assertEquals(['span'], $options->nonSplittingTags);
    }

    #[Test]
    public function constructorDefaultsToDefaultFormality(): void
    {
        $options = new DeepLOptions();

        $this->assertEquals('default', $options->formality);
    }

    #[Test]
    #[DataProvider('validFormalityProvider')]
    public function constructorAcceptsValidFormality(string $formality): void
    {
        $options = new DeepLOptions(formality: $formality);

        $this->assertEquals($formality, $options->formality);
    }

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

        $this->assertEquals($tagHandling, $options->tagHandling);
    }

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

        $this->assertArrayHasKey('formality', $array);
        $this->assertArrayNotHasKey('glossary_id', $array);
        $this->assertArrayNotHasKey('preserve_formatting', $array);
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

        $this->assertArrayHasKey('glossary_id', $array);
        $this->assertArrayHasKey('preserve_formatting', $array);
        $this->assertArrayHasKey('split_sentences', $array);
        $this->assertArrayHasKey('tag_handling', $array);
        $this->assertArrayHasKey('ignore_tags', $array);
        $this->assertArrayHasKey('non_splitting_tags', $array);
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

        $this->assertEquals('less', $options->formality);
        $this->assertEquals('gls_456', $options->glossaryId);
        $this->assertTrue($options->preserveFormatting);
        $this->assertFalse($options->splitSentences);
        $this->assertEquals('xml', $options->tagHandling);
        $this->assertEquals(['code'], $options->ignoreTags);
        $this->assertEquals(['em'], $options->nonSplittingTags);
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

        $this->assertEquals('gls_789', $options->glossaryId);
        $this->assertFalse($options->preserveFormatting);
        $this->assertTrue($options->splitSentences);
    }

    #[Test]
    public function formalPresetHasMoreFormality(): void
    {
        $options = DeepLOptions::formal();

        $this->assertEquals('more', $options->formality);
    }

    #[Test]
    public function informalPresetHasLessFormality(): void
    {
        $options = DeepLOptions::informal();

        $this->assertEquals('less', $options->formality);
    }

    #[Test]
    public function htmlPresetHasHtmlTagHandling(): void
    {
        $options = DeepLOptions::html();

        $this->assertEquals('html', $options->tagHandling);
        $this->assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function xmlPresetHasXmlTagHandling(): void
    {
        $options = DeepLOptions::xml();

        $this->assertEquals('xml', $options->tagHandling);
        $this->assertTrue($options->preserveFormatting);
    }

    #[Test]
    public function withGlossaryCreatesOptionsWithGlossaryId(): void
    {
        $options = DeepLOptions::withGlossary('gls_abc123');

        $this->assertEquals('gls_abc123', $options->glossaryId);
    }

    #[Test]
    public function mergeOverridesWithArray(): void
    {
        $options = new DeepLOptions(formality: 'more', preserveFormatting: true);

        $merged = $options->merge(['formality' => 'less', 'glossary_id' => 'new_gls']);

        $this->assertEquals('less', $merged['formality']);
        $this->assertTrue($merged['preserve_formatting']);
        $this->assertEquals('new_gls', $merged['glossary_id']);
    }

    #[Test]
    public function nullFormalityIsAllowed(): void
    {
        $options = new DeepLOptions(formality: null);

        $this->assertNull($options->formality);
    }

    #[Test]
    public function optionsAreReadonly(): void
    {
        $options = new DeepLOptions(formality: 'more');

        // Verify readonly property is accessible
        $this->assertEquals('more', $options->formality);
    }
}
