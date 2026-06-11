<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ImageGenerationOptions::class)]
class ImageGenerationOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $options = new ImageGenerationOptions(
            model: 'dall-e-3',
            size: '1024x1024',
            quality: 'hd',
            style: 'vivid',
            format: 'b64_json',
        );

        self::assertEquals('dall-e-3', $options->model);
        self::assertEquals('1024x1024', $options->size);
        self::assertEquals('hd', $options->quality);
        self::assertEquals('vivid', $options->style);
        self::assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $options = new ImageGenerationOptions();

        self::assertEquals('dall-e-3', $options->model);
        self::assertEquals('1024x1024', $options->size);
        self::assertEquals('standard', $options->quality);
        self::assertEquals('vivid', $options->style);
        self::assertEquals('url', $options->format);
    }

    #[Test]
    #[DataProvider('validDalle3SizeProvider')]
    public function constructorAcceptsValidDalle3Sizes(string $size): void
    {
        $options = new ImageGenerationOptions(model: 'dall-e-3', size: $size);

        self::assertEquals($size, $options->size);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validDalle3SizeProvider(): array
    {
        return [
            '1024x1024' => ['1024x1024'],
            '1792x1024' => ['1792x1024'],
            '1024x1792' => ['1024x1792'],
        ];
    }

    #[Test]
    #[DataProvider('validDalle2SizeProvider')]
    public function constructorAcceptsValidDalle2Sizes(string $size): void
    {
        $options = new ImageGenerationOptions(model: 'dall-e-2', size: $size);

        self::assertEquals($size, $options->size);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validDalle2SizeProvider(): array
    {
        return [
            '256x256' => ['256x256'],
            '512x512' => ['512x512'],
            '1024x1024' => ['1024x1024'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidSizeOnDalle3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid size');

        new ImageGenerationOptions(model: 'dall-e-3', size: '256x256');
    }

    #[Test]
    public function constructorThrowsForInvalidSizeOnDalle2(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid size');

        new ImageGenerationOptions(model: 'dall-e-2', size: '1792x1024');
    }

    #[Test]
    #[DataProvider('validQualityProvider')]
    public function constructorAcceptsValidQuality(string $quality): void
    {
        $options = new ImageGenerationOptions(quality: $quality);

        self::assertEquals($quality, $options->quality);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validQualityProvider(): array
    {
        return [
            'standard' => ['standard'],
            'hd' => ['hd'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidQuality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quality must be one of');

        new ImageGenerationOptions(quality: 'ultra');
    }

    #[Test]
    #[DataProvider('validStyleProvider')]
    public function constructorAcceptsValidStyle(string $style): void
    {
        $options = new ImageGenerationOptions(style: $style);

        self::assertEquals($style, $options->style);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validStyleProvider(): array
    {
        return [
            'vivid' => ['vivid'],
            'natural' => ['natural'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidStyle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('style must be one of');

        new ImageGenerationOptions(style: 'artistic');
    }

    #[Test]
    #[DataProvider('validFormatProvider')]
    public function constructorAcceptsValidFormat(string $format): void
    {
        $options = new ImageGenerationOptions(format: $format);

        self::assertEquals($format, $options->format);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validFormatProvider(): array
    {
        return [
            'url' => ['url'],
            'b64_json' => ['b64_json'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be one of');

        new ImageGenerationOptions(format: 'binary');
    }

    #[Test]
    #[DataProvider('validModelProvider')]
    public function constructorAcceptsValidModel(string $model): void
    {
        $options = new ImageGenerationOptions(model: $model);

        self::assertEquals($model, $options->model);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validModelProvider(): array
    {
        return [
            'dall-e-2' => ['dall-e-2'],
            'dall-e-3' => ['dall-e-3'],
        ];
    }

    #[Test]
    public function constructorThrowsForInvalidModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('model must be one of');

        new ImageGenerationOptions(model: 'dall-e-4');
    }

    #[Test]
    #[DataProvider('gptImageModelProvider')]
    public function constructorAcceptsGptImageModelsByPrefix(string $model): void
    {
        // gpt-image-* models are accepted by prefix so future point releases need no code change.
        $options = new ImageGenerationOptions(model: $model, size: '1024x1024');

        self::assertEquals($model, $options->model);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gptImageModelProvider(): array
    {
        return [
            'gpt-image-1' => ['gpt-image-1'],
            'gpt-image-1-mini' => ['gpt-image-1-mini'],
            'gpt-image-2' => ['gpt-image-2'],
            'dated point release' => ['gpt-image-2-2026-04-21'],
        ];
    }

    #[Test]
    #[DataProvider('gptImageLookalikeProvider')]
    public function constructorRejectsGptImageLookalikesWithoutTrailingDash(string $model): void
    {
        // The prefix is strict ("gpt-image-"), so near-misses without the dash are not the family.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('model must be one of');

        $options = new ImageGenerationOptions(model: $model);
        self::fail('Expected the constructor to reject model: ' . $options->model);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gptImageLookalikeProvider(): array
    {
        return [
            'gpt-imagery' => ['gpt-imagery'],
            'gpt-imageX' => ['gpt-imageX'],
            'gpt-image' => ['gpt-image'],
        ];
    }

    #[Test]
    #[DataProvider('validGptImageSizeProvider')]
    public function constructorAcceptsValidGptImageSizes(string $size): void
    {
        $options = new ImageGenerationOptions(model: 'gpt-image-1', size: $size);

        self::assertEquals($size, $options->size);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validGptImageSizeProvider(): array
    {
        return [
            '1024x1024' => ['1024x1024'],
            '1536x1024' => ['1536x1024'],
            '1024x1536' => ['1024x1536'],
            'auto' => ['auto'],
        ];
    }

    #[Test]
    #[DataProvider('validArbitraryGptImageSizeProvider')]
    public function constructorAcceptsArbitraryGptImageSizes(string $size): void
    {
        // gpt-image-* accepts any WIDTHxHEIGHT with both dimensions divisible
        // by 16, aspect ratio between 1:3 and 3:1 (inclusive), max 3840x2160
        // (per OpenAI docs, June 2026). Other extensions rely on this contract.
        $options = new ImageGenerationOptions(model: 'gpt-image-2', size: $size);

        self::assertEquals($size, $options->size);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validArbitraryGptImageSizeProvider(): array
    {
        return [
            'former DALL-E-3 wide size'   => ['1792x1024'],
            '16:9 HD'                     => ['2048x1152'],
            'maximum 3840x2160'           => ['3840x2160'],
            'exact 1:3 portrait'          => ['720x2160'],
            'exact 3:1 landscape'         => ['2160x720'],
            'widest at max width'         => ['3840x1280'],
            'minimum 16x16'               => ['16x16'],
            '2:1 landscape'               => ['1024x512'],
        ];
    }

    #[Test]
    #[DataProvider('invalidArbitraryGptImageSizeProvider')]
    public function constructorRejectsInvalidArbitraryGptImageSizes(string $size, int $expectedCode): void
    {
        try {
            $options = new ImageGenerationOptions(model: 'gpt-image-2', size: $size);
            self::fail(sprintf('Expected the constructor to reject size "%s", got %s', $size, $options::class));
        } catch (InvalidArgumentException $e) {
            self::assertSame($expectedCode, $e->getCode());
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function invalidArbitraryGptImageSizeProvider(): array
    {
        return [
            // Both dimensions must be divisible by 16.
            'width not divisible by 16'  => ['1000x1024', 4810231766],
            'height not divisible by 16' => ['1024x1000', 4810231766],
            'zero dimensions'            => ['0x0', 4810231766],
            // Maximum size is 3840x2160.
            'width above 3840'           => ['3856x2160', 7269345118],
            'height above 2160'          => ['3840x2176', 7269345118],
            // Aspect ratio must stay between 1:3 and 3:1.
            'taller than 1:3'            => ['512x1552', 6203914577],
            'wider than 3:1'             => ['1552x512', 6203914577],
            // Malformed strings are rejected outright.
            'missing height'             => ['1024x', 9662872869],
            'missing width'              => ['x1024', 9662872869],
            'non-numeric'                => ['widexhigh', 9662872869],
            'negative width'             => ['-1024x1024', 9662872869],
            'whitespace'                 => [' 1024x1024', 9662872869],
        ];
    }

    #[Test]
    public function constructorStillRejectsArbitrarySizesForDalleModels(): void
    {
        // The WxH freedom is a gpt-image-* contract only; DALL·E models keep
        // their fixed size lists.
        try {
            $options = new ImageGenerationOptions(model: 'dall-e-3', size: '2048x1152');
            self::fail(sprintf('Expected the constructor to reject the size, got %s', $options::class));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid size', $e->getMessage());
        }
    }

    #[Test]
    public function getValidSizesReturnsGptImageSizes(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes('gpt-image-1');

        self::assertContains('1536x1024', $sizes);
        self::assertContains('1024x1536', $sizes);
        self::assertNotContains('1792x1024', $sizes);
    }

    #[Test]
    public function toArrayIncludesAllOptions(): void
    {
        $options = new ImageGenerationOptions(
            model: 'dall-e-3',
            size: '1792x1024',
            quality: 'hd',
            style: 'natural',
            format: 'b64_json',
        );

        $array = $options->toArray();

        self::assertEquals('dall-e-3', $array['model']);
        self::assertEquals('1792x1024', $array['size']);
        self::assertEquals('hd', $array['quality']);
        self::assertEquals('natural', $array['style']);
        self::assertEquals('b64_json', $array['response_format']);
    }

    #[Test]
    public function fromArrayCreatesOptions(): void
    {
        $array = [
            'model' => 'dall-e-3',
            'size' => '1024x1792',
            'quality' => 'hd',
            'style' => 'natural',
            'format' => 'b64_json',
        ];

        $options = ImageGenerationOptions::fromArray($array);

        self::assertEquals('dall-e-3', $options->model);
        self::assertEquals('1024x1792', $options->size);
        self::assertEquals('hd', $options->quality);
        self::assertEquals('natural', $options->style);
        self::assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function fromArraySupportsResponseFormatKey(): void
    {
        $array = [
            'response_format' => 'b64_json',
        ];

        $options = ImageGenerationOptions::fromArray($array);

        self::assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function configurationDefaultsToNull(): void
    {
        $options = new ImageGenerationOptions();

        self::assertNull($options->configuration);
    }

    #[Test]
    public function constructorAcceptsConfigurationIdentifier(): void
    {
        $options = new ImageGenerationOptions(configuration: 'alt-text-images');

        self::assertSame('alt-text-images', $options->configuration);
    }

    #[Test]
    public function fromArrayReadsConfigurationIdentifier(): void
    {
        $options = ImageGenerationOptions::fromArray(['configuration' => 'alt-text-images']);

        self::assertSame('alt-text-images', $options->configuration);
    }

    #[Test]
    public function toArrayOmitsConfiguration(): void
    {
        // `configuration` is consumer metadata for usage attribution,
        // not an Images API parameter — it must never reach the payload.
        $options = new ImageGenerationOptions(configuration: 'alt-text-images');

        self::assertArrayNotHasKey('configuration', $options->toArray());
    }

    #[Test]
    public function configurationDoesNotRelaxSizeValidation(): void
    {
        // The configuration is pure metadata: size is still validated
        // against the concrete model value — the consumer must resolve
        // the model via resolveModelForConfiguration() BEFORE building
        // the options.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid size');

        new ImageGenerationOptions(model: 'dall-e-3', size: '256x256', configuration: 'alt-text-images');
    }

    #[Test]
    public function configurationDoesNotAffectGptImageSizeValidation(): void
    {
        $options = new ImageGenerationOptions(
            model: 'gpt-image-2',
            size: '2048x1152',
            configuration: 'alt-text-images',
        );

        self::assertSame('2048x1152', $options->size);
    }

    #[Test]
    public function landscapePresetReturns1792x1024(): void
    {
        $options = ImageGenerationOptions::landscape();

        self::assertEquals('1792x1024', $options->size);
    }

    #[Test]
    public function portraitPresetReturns1024x1792(): void
    {
        $options = ImageGenerationOptions::portrait();

        self::assertEquals('1024x1792', $options->size);
    }

    #[Test]
    public function hdPresetHasHdQuality(): void
    {
        $options = ImageGenerationOptions::hd();

        self::assertEquals('hd', $options->quality);
    }

    #[Test]
    public function hdPresetAcceptsCustomSize(): void
    {
        $options = ImageGenerationOptions::hd('1792x1024');

        self::assertEquals('hd', $options->quality);
        self::assertEquals('1792x1024', $options->size);
    }

    #[Test]
    public function naturalPresetHasNaturalStyle(): void
    {
        $options = ImageGenerationOptions::natural();

        self::assertEquals('natural', $options->style);
    }

    #[Test]
    public function getValidSizesReturnsCorrectSizesForDalle3(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes('dall-e-3');

        self::assertContains('1024x1024', $sizes);
        self::assertContains('1792x1024', $sizes);
        self::assertContains('1024x1792', $sizes);
        self::assertNotContains('256x256', $sizes);
    }

    #[Test]
    public function getValidSizesReturnsCorrectSizesForDalle2(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes('dall-e-2');

        self::assertContains('256x256', $sizes);
        self::assertContains('512x512', $sizes);
        self::assertContains('1024x1024', $sizes);
        self::assertNotContains('1792x1024', $sizes);
    }

    #[Test]
    public function getValidSizesDefaultsToDalle3(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes();

        self::assertContains('1792x1024', $sizes);
    }

    #[Test]
    public function optionsAreReadonly(): void
    {
        $options = new ImageGenerationOptions();

        // Verify readonly properties are accessible
        self::assertIsString($options->model);
        self::assertIsString($options->size);
        self::assertIsString($options->quality);
        self::assertIsString($options->style);
        self::assertIsString($options->format);
    }
}
