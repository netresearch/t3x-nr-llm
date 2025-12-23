<?php

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

        $this->assertEquals('dall-e-3', $options->model);
        $this->assertEquals('1024x1024', $options->size);
        $this->assertEquals('hd', $options->quality);
        $this->assertEquals('vivid', $options->style);
        $this->assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $options = new ImageGenerationOptions();

        $this->assertEquals('dall-e-3', $options->model);
        $this->assertEquals('1024x1024', $options->size);
        $this->assertEquals('standard', $options->quality);
        $this->assertEquals('vivid', $options->style);
        $this->assertEquals('url', $options->format);
    }

    #[Test]
    #[DataProvider('validDalle3SizeProvider')]
    public function constructorAcceptsValidDalle3Sizes(string $size): void
    {
        $options = new ImageGenerationOptions(model: 'dall-e-3', size: $size);

        $this->assertEquals($size, $options->size);
    }

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

        $this->assertEquals($size, $options->size);
    }

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

        $this->assertEquals($quality, $options->quality);
    }

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

        $this->assertEquals($style, $options->style);
    }

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

        $this->assertEquals($format, $options->format);
    }

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

        $this->assertEquals($model, $options->model);
    }

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

        $this->assertEquals('dall-e-3', $array['model']);
        $this->assertEquals('1792x1024', $array['size']);
        $this->assertEquals('hd', $array['quality']);
        $this->assertEquals('natural', $array['style']);
        $this->assertEquals('b64_json', $array['response_format']);
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

        $this->assertEquals('dall-e-3', $options->model);
        $this->assertEquals('1024x1792', $options->size);
        $this->assertEquals('hd', $options->quality);
        $this->assertEquals('natural', $options->style);
        $this->assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function fromArraySupportsResponseFormatKey(): void
    {
        $array = [
            'response_format' => 'b64_json',
        ];

        $options = ImageGenerationOptions::fromArray($array);

        $this->assertEquals('b64_json', $options->format);
    }

    #[Test]
    public function landscapePresetReturns1792x1024(): void
    {
        $options = ImageGenerationOptions::landscape();

        $this->assertEquals('1792x1024', $options->size);
    }

    #[Test]
    public function portraitPresetReturns1024x1792(): void
    {
        $options = ImageGenerationOptions::portrait();

        $this->assertEquals('1024x1792', $options->size);
    }

    #[Test]
    public function hdPresetHasHdQuality(): void
    {
        $options = ImageGenerationOptions::hd();

        $this->assertEquals('hd', $options->quality);
    }

    #[Test]
    public function hdPresetAcceptsCustomSize(): void
    {
        $options = ImageGenerationOptions::hd('1792x1024');

        $this->assertEquals('hd', $options->quality);
        $this->assertEquals('1792x1024', $options->size);
    }

    #[Test]
    public function naturalPresetHasNaturalStyle(): void
    {
        $options = ImageGenerationOptions::natural();

        $this->assertEquals('natural', $options->style);
    }

    #[Test]
    public function getValidSizesReturnsCorrectSizesForDalle3(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes('dall-e-3');

        $this->assertContains('1024x1024', $sizes);
        $this->assertContains('1792x1024', $sizes);
        $this->assertContains('1024x1792', $sizes);
        $this->assertNotContains('256x256', $sizes);
    }

    #[Test]
    public function getValidSizesReturnsCorrectSizesForDalle2(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes('dall-e-2');

        $this->assertContains('256x256', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('1024x1024', $sizes);
        $this->assertNotContains('1792x1024', $sizes);
    }

    #[Test]
    public function getValidSizesDefaultsToDalle3(): void
    {
        $sizes = ImageGenerationOptions::getValidSizes();

        $this->assertContains('1792x1024', $sizes);
    }

    #[Test]
    public function optionsAreReadonly(): void
    {
        $options = new ImageGenerationOptions();

        // Verify readonly properties are accessible
        $this->assertIsString($options->model);
        $this->assertIsString($options->size);
        $this->assertIsString($options->quality);
        $this->assertIsString($options->style);
        $this->assertIsString($options->format);
    }
}
