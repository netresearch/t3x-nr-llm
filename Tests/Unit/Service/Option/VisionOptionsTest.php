<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(VisionOptions::class)]
class VisionOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $options = new VisionOptions(
            detailLevel: 'high',
            maxTokens: 500,
            temperature: 0.7,
            provider: 'openai',
            model: 'gpt-4-vision-preview',
        );

        self::assertEquals('high', $options->getDetailLevel());
        self::assertEquals(500, $options->getMaxTokens());
        self::assertEquals(0.7, $options->getTemperature());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4-vision-preview', $options->getModel());
    }

    #[Test]
    #[DataProvider('invalidDetailLevelProvider')]
    public function constructorThrowsForInvalidDetailLevel(string $detailLevel): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('detail_level must be one of: auto, low, high');

        new VisionOptions(detailLevel: $detailLevel);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDetailLevelProvider(): array
    {
        return [
            'medium' => ['medium'],
            'very_high' => ['very_high'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('validDetailLevelProvider')]
    public function constructorAcceptsValidDetailLevel(string $detailLevel): void
    {
        $options = new VisionOptions(detailLevel: $detailLevel);

        self::assertEquals($detailLevel, $options->getDetailLevel());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDetailLevelProvider(): array
    {
        return [
            'auto' => ['auto'],
            'low' => ['low'],
            'high' => ['high'],
        ];
    }

    #[Test]
    public function constructorThrowsForNegativeMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be a positive integer');

        new VisionOptions(maxTokens: 0);
    }

    #[Test]
    #[DataProvider('invalidTemperatureProvider')]
    public function constructorThrowsForInvalidTemperature(float $temperature): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        new VisionOptions(temperature: $temperature);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function invalidTemperatureProvider(): array
    {
        return [
            'negative' => [-0.1],
            'too high' => [2.1],
        ];
    }

    // Factory Presets

    #[Test]
    public function altTextPresetIsOptimizedForShortDescriptions(): void
    {
        $options = VisionOptions::altText();

        self::assertEquals('low', $options->getDetailLevel());
        self::assertEquals(100, $options->getMaxTokens());
        self::assertEquals(0.5, $options->getTemperature());
    }

    #[Test]
    public function detailedPresetIsOptimizedForDescriptions(): void
    {
        $options = VisionOptions::detailed();

        self::assertEquals('high', $options->getDetailLevel());
        self::assertEquals(500, $options->getMaxTokens());
        self::assertEquals(0.7, $options->getTemperature());
    }

    #[Test]
    public function quickPresetIsCostOptimized(): void
    {
        $options = VisionOptions::quick();

        self::assertEquals('low', $options->getDetailLevel());
        self::assertEquals(200, $options->getMaxTokens());
        self::assertEquals(0.5, $options->getTemperature());
    }

    #[Test]
    public function comprehensivePresetHasHighTokenLimit(): void
    {
        $options = VisionOptions::comprehensive();

        self::assertEquals('high', $options->getDetailLevel());
        self::assertEquals(1000, $options->getMaxTokens());
        self::assertEquals(0.7, $options->getTemperature());
    }

    // Fluent Setters

    #[Test]
    public function withDetailLevelReturnsNewInstance(): void
    {
        $options1 = new VisionOptions(detailLevel: 'low');
        $options2 = $options1->withDetailLevel('high');

        self::assertNotSame($options1, $options2);
        self::assertEquals('low', $options1->getDetailLevel());
        self::assertEquals('high', $options2->getDetailLevel());
    }

    #[Test]
    public function withDetailLevelValidatesValue(): void
    {
        $options = new VisionOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withDetailLevel('invalid');
    }

    #[Test]
    public function withMaxTokensReturnsNewInstance(): void
    {
        $options1 = new VisionOptions(maxTokens: 100);
        $options2 = $options1->withMaxTokens(200);

        self::assertEquals(100, $options1->getMaxTokens());
        self::assertEquals(200, $options2->getMaxTokens());
    }

    #[Test]
    public function withMaxTokensValidatesValue(): void
    {
        $options = new VisionOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withMaxTokens(0);
    }

    #[Test]
    public function withTemperatureReturnsNewInstance(): void
    {
        $options1 = new VisionOptions(temperature: 0.5);
        $options2 = $options1->withTemperature(0.8);

        self::assertEquals(0.5, $options1->getTemperature());
        self::assertEquals(0.8, $options2->getTemperature());
    }

    #[Test]
    public function withTemperatureValidatesValue(): void
    {
        $options = new VisionOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withTemperature(3.0);
    }

    #[Test]
    public function withProviderReturnsNewInstance(): void
    {
        $options1 = new VisionOptions(provider: 'openai');
        $options2 = $options1->withProvider('claude');

        self::assertEquals('openai', $options1->getProvider());
        self::assertEquals('claude', $options2->getProvider());
    }

    #[Test]
    public function withModelReturnsNewInstance(): void
    {
        $options1 = new VisionOptions(model: 'gpt-4-vision');
        $options2 = $options1->withModel('claude-3-opus');

        self::assertEquals('gpt-4-vision', $options1->getModel());
        self::assertEquals('claude-3-opus', $options2->getModel());
    }

    // Array Conversion

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new VisionOptions(detailLevel: 'high');

        $array = $options->toArray();

        self::assertArrayHasKey('detail_level', $array);
        self::assertArrayNotHasKey('max_tokens', $array);
        self::assertArrayNotHasKey('temperature', $array);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $options = new VisionOptions(
            detailLevel: 'high',
            maxTokens: 500,
        );

        $array = $options->toArray();

        self::assertArrayHasKey('detail_level', $array);
        self::assertArrayHasKey('max_tokens', $array);
    }

    #[Test]
    public function chainedFluentSettersWork(): void
    {
        $options = VisionOptions::altText()
            ->withDetailLevel('high')
            ->withMaxTokens(300)
            ->withTemperature(0.6)
            ->withProvider('openai')
            ->withModel('gpt-4-vision');

        self::assertEquals('high', $options->getDetailLevel());
        self::assertEquals(300, $options->getMaxTokens());
        self::assertEquals(0.6, $options->getTemperature());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals('gpt-4-vision', $options->getModel());
    }
}
