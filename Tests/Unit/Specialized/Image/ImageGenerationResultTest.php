<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

        self::assertEquals('https://example.com/image.png', $result->url);
        self::assertEquals('base64data', $result->base64);
        self::assertEquals('A cat', $result->prompt);
        self::assertEquals('A cute orange cat', $result->revisedPrompt);
        self::assertEquals('dall-e-3', $result->model);
        self::assertEquals('1024x1024', $result->size);
        self::assertEquals('dall-e', $result->provider);
        self::assertEquals(['seed' => 12345], $result->metadata);
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

        self::assertTrue($result->hasBase64());
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

        self::assertFalse($result->hasBase64());
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

        self::assertFalse($result->hasBase64());
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

        self::assertEquals($expectedWidth, $dimensions['width']);
        self::assertEquals($expectedHeight, $dimensions['height']);
    }

    /**
     * @return array<string, array{string, int, int}>
     */
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

        self::assertEquals(1792, $result->getWidth());
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

        self::assertEquals(1792, $result->getHeight());
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

        self::assertTrue($result->isLandscape());
        self::assertFalse($result->isPortrait());
        self::assertFalse($result->isSquare());
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

        self::assertTrue($result->isPortrait());
        self::assertFalse($result->isLandscape());
        self::assertFalse($result->isSquare());
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

        self::assertTrue($result->isSquare());
        self::assertFalse($result->isLandscape());
        self::assertFalse($result->isPortrait());
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

        self::assertTrue($result->wasPromptRevised());
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

        self::assertFalse($result->wasPromptRevised());
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

        self::assertFalse($result->wasPromptRevised());
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

        self::assertEquals('A detailed cat', $result->getEffectivePrompt());
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

        self::assertEquals('A cat', $result->getEffectivePrompt());
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

        self::assertNotNull($dataUrl);
        self::assertStringStartsWith('data:image/png;base64,', $dataUrl);
        self::assertStringContainsString($base64, $dataUrl);
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

        self::assertNull($result->toDataUrl());
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

        self::assertEquals($originalData, $result->getBinaryContent());
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

        self::assertNull($result->getBinaryContent());
    }

    #[Test]
    public function wasPromptRevisedReturnsFalseWhenEmpty(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'A cat',
            revisedPrompt: '',
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        self::assertFalse($result->wasPromptRevised());
    }

    #[Test]
    public function toDataUrlWithCustomMimeType(): void
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

        $dataUrl = $result->toDataUrl('image/jpeg');

        self::assertNotNull($dataUrl);
        self::assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);
    }

    #[Test]
    public function getDimensionsHandlesSingleValueSize(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024', // No 'x' separator - should use same value for both
            provider: 'test',
        );

        $dimensions = $result->getDimensions();

        self::assertEquals(1024, $dimensions['width']);
        self::assertEquals(1024, $dimensions['height']);
    }

    #[Test]
    public function getBinaryContentReturnsNullForInvalidBase64(): void
    {
        $result = new ImageGenerationResult(
            url: '',
            base64: '!@#$%^&*()_+invalid_base64_that_cannot_decode',
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        // Invalid base64 should return null
        self::assertNull($result->getBinaryContent());
    }

    #[Test]
    public function saveToFileSavesBase64Content(): void
    {
        $originalData = 'This is fake PNG image data for testing';
        $base64 = base64_encode($originalData);
        $tempFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.png';

        try {
            $result = new ImageGenerationResult(
                url: 'https://example.com/fallback.png',
                base64: $base64,
                prompt: 'test',
                revisedPrompt: null,
                model: 'test',
                size: '1024x1024',
                provider: 'test',
            );

            $success = $result->saveToFile($tempFile);

            self::assertTrue($success);
            self::assertFileExists($tempFile);
            self::assertEquals($originalData, file_get_contents($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function saveToFileReturnsFalseForInvalidUrl(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.png';

        try {
            $result = new ImageGenerationResult(
                url: 'https://invalid-url-that-does-not-exist.test/image.png',
                base64: null,
                prompt: 'test',
                revisedPrompt: null,
                model: 'test',
                size: '1024x1024',
                provider: 'test',
            );

            $success = $result->saveToFile($tempFile);

            // Should return false as URL download fails
            self::assertFalse($success);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function downloadFromUrlReturnsNullForInvalidUrl(): void
    {
        $result = new ImageGenerationResult(
            url: 'https://invalid-url-that-does-not-exist.test/image.png',
            base64: null,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        // Should return null as URL download fails
        self::assertNull($result->downloadFromUrl());
    }

    #[Test]
    public function saveToFileReturnsFalseForInvalidPath(): void
    {
        $originalData = 'test data';
        $base64 = base64_encode($originalData);
        // Use a path that cannot be written to
        $invalidPath = '/nonexistent/directory/that/cannot/exist/image.png';

        $result = new ImageGenerationResult(
            url: 'https://example.com/image.png',
            base64: $base64,
            prompt: 'test',
            revisedPrompt: null,
            model: 'test',
            size: '1024x1024',
            provider: 'test',
        );

        $success = $result->saveToFile($invalidPath);

        // Should return false as path is invalid
        self::assertFalse($success);
    }
}
