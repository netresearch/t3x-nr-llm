<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ImageGenerationResult::class)]
class ImageGenerationResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: 'base64data',
            prompt: 'A cat',
            revisedPrompt: 'A cute orange cat',
            model: 'dall-e-3',
            size: '1024x1024',
            provider: 'dall-e',
            metadata: ['seed' => 12345],
        );

        $this->assertEquals('https://example.com/image.png', $result->url);
        $this->assertEquals('base64data', $result->base64);
        $this->assertEquals('A cat', $result->prompt);
        $this->assertEquals('A cute orange cat', $result->revisedPrompt);
        $this->assertEquals('dall-e-3', $result->model);
        $this->assertEquals('1024x1024', $result->size);
        $this->assertEquals('dall-e', $result->provider);
        $this->assertEquals(['seed' => 12345], $result->metadata);
    }

    #[Test]
    public function hasBase64ReturnsTrueWhenPresent(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: 'base64encodeddata',
            prompt: 'test',
            revisedPrompt: null,
            model: 'dall-e-3',
            size: '1024x1024',
            provider: 'dall-e',
        );

        $this->assertTrue($result->hasBase64());
    }

    #[Test]
    public function hasBase64ReturnsFalseWhenNull(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'dall-e-3',
            size: '1024x1024',
            provider: 'dall-e',
        );

        $this->assertFalse($result->hasBase64());
    }

    #[Test]
    public function hasBase64ReturnsFalseWhenEmpty(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: '',
            prompt: 'test',
            revisedPrompt: null,
            model: 'dall-e-3',
            size: '1024x1024',
            provider: 'dall-e',
        );

        $this->assertFalse($result->hasBase64());
    }

    #[Test]
    #[DataProvider('dimensionsProvider')]
    public function getDimensionsParsesSizeCorrectly(string $size, int $expectedWidth, int $expectedHeight): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'dall-e-3',
            size: $size,
            provider: 'dall-e',
        );

        $dimensions = $result->getDimensions();

        $this->assertEquals($expectedWidth, $dimensions['width']);
        $this->assertEquals($expectedHeight, $dimensions['height']);
    }

    public static function dimensionsProvider(): array
    {
        return [
            'square 1024' => ['1024x1024', 1024, 1024],
            'landscape' => ['1792x1024', 1792, 1024],
            'portrait' => ['1024x1792', 1024, 1792],
            'small square' => ['256x256', 256, 256],
            'medium square' => ['512x512', 512, 512],
        ];
    }

    #[Test]
    public function getWidthReturnsCorrectValue(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1792x1024',
            provider: 'test',
        );

        $this->assertEquals(1792, $result->getWidth());
    }

    #[Test]
    public function getHeightReturnsCorrectValue(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1792',
            provider: 'test',
        );

        $this->assertEquals(1792, $result->getHeight());
    }

    #[Test]
    public function isLandscapeReturnsTrueForWiderImages(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1792x1024',
            provider: 'test',
        );

        $this->assertTrue($result->isLandscape());
        $this->assertFalse($result->isPortrait());
        $this->assertFalse($result->isSquare());
    }

    #[Test]
    public function isPortraitReturnsTrueForTallerImages(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1792',
            provider: 'test',
        );

        $this->assertTrue($result->isPortrait());
        $this->assertFalse($result->isLandscape());
        $this->assertFalse($result->isSquare());
    }

    #[Test]
    public function isSquareReturnsTrueForEqualDimensions(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertTrue($result->isSquare());
        $this->assertFalse($result->isLandscape());
        $this->assertFalse($result->isPortrait());
    }

    #[Test]
    public function wasPromptRevisedReturnsTrueWhenDifferent(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: 'A detailed photorealistic image of an orange tabby cat',
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertTrue($result->wasPromptRevised());
    }

    #[Test]
    public function wasPromptRevisedReturnsFalseWhenSame(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: 'A cat',
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertFalse($result->wasPromptRevised());
    }

    #[Test]
    public function wasPromptRevisedReturnsFalseWhenNull(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertFalse($result->wasPromptRevised());
    }

    #[Test]
    public function getEffectivePromptReturnsRevisedWhenAvailable(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: 'A detailed cat',
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertEquals('A detailed cat', $result->getEffectivePrompt());
    }

    #[Test]
    public function getEffectivePromptReturnsOriginalWhenNoRevision(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertEquals('A cat', $result->getEffectivePrompt());
    }

    #[Test]
    public function toDataUrlReturnsFormattedDataUrl(): void
    {
        $base64 = base64_encode('fake image data');
        $result = new ImageGenerationResult(
            url: '',
            base64: $base64,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $dataUrl = $result->toDataUrl();

        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
        $this->assertStringContainsString($base64, $dataUrl);
    }

    #[Test]
    public function toDataUrlReturnsNullWhenNoBase64(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertNull($result->toDataUrl());
    }

    #[Test]
    public function getBinaryContentDecodesBase64(): void
    {
        $originalData = 'This is fake image binary data';
        $base64 = base64_encode($originalData);

        $result = new ImageGenerationResult(
            url: '',
            base64: $base64,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertEquals($originalData, $result->getBinaryContent());
    }

    #[Test]
    public function getBinaryContentReturnsNullWhenNoBase64(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $this->assertNull($result->getBinaryContent());
    }
}
