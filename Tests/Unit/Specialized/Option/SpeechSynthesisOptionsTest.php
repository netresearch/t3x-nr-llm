<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use InvalidArgumentException;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SpeechSynthesisOptions::class)]
class SpeechSynthesisOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $options = new SpeechSynthesisOptions();

        self::assertEquals('tts-1', $options->model);
        self::assertEquals('alloy', $options->voice);
        self::assertEquals('mp3', $options->format);
        self::assertEquals(1.0, $options->speed);
    }

    #[Test]
    public function constructorAcceptsCustomValues(): void
    {
        $options = new SpeechSynthesisOptions(
            model: 'tts-1-hd',
            voice: 'shimmer',
            format: 'opus',
            speed: 1.5,
        );

        self::assertEquals('tts-1-hd', $options->model);
        self::assertEquals('shimmer', $options->voice);
        self::assertEquals('opus', $options->format);
        self::assertEquals(1.5, $options->speed);
    }

    #[Test]
    #[DataProvider('validVoicesProvider')]
    public function constructorAcceptsAllValidVoices(string $voice): void
    {
        $options = new SpeechSynthesisOptions(voice: $voice);

        self::assertEquals($voice, $options->voice);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validVoicesProvider(): array
    {
        return [
            'alloy' => ['alloy'],
            'echo' => ['echo'],
            'fable' => ['fable'],
            'onyx' => ['onyx'],
            'nova' => ['nova'],
            'shimmer' => ['shimmer'],
        ];
    }

    #[Test]
    public function constructorRejectsInvalidVoice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeechSynthesisOptions(voice: 'invalid_voice');
    }

    #[Test]
    #[DataProvider('validModelsProvider')]
    public function constructorAcceptsAllValidModels(string $model): void
    {
        $options = new SpeechSynthesisOptions(model: $model);

        self::assertEquals($model, $options->model);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validModelsProvider(): array
    {
        return [
            'tts-1' => ['tts-1'],
            'tts-1-hd' => ['tts-1-hd'],
        ];
    }

    #[Test]
    public function constructorRejectsInvalidModel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeechSynthesisOptions(model: 'invalid-model');
    }

    #[Test]
    #[DataProvider('validFormatsProvider')]
    public function constructorAcceptsAllValidFormats(string $format): void
    {
        $options = new SpeechSynthesisOptions(format: $format);

        self::assertEquals($format, $options->format);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFormatsProvider(): array
    {
        return [
            'mp3' => ['mp3'],
            'opus' => ['opus'],
            'aac' => ['aac'],
            'flac' => ['flac'],
            'wav' => ['wav'],
            'pcm' => ['pcm'],
        ];
    }

    #[Test]
    public function constructorRejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeechSynthesisOptions(format: 'invalid_format');
    }

    #[Test]
    public function constructorAcceptsValidSpeedRange(): void
    {
        $min = new SpeechSynthesisOptions(speed: 0.25);
        $max = new SpeechSynthesisOptions(speed: 4.0);

        self::assertEquals(0.25, $min->speed);
        self::assertEquals(4.0, $max->speed);
    }

    #[Test]
    public function constructorRejectsSpeedBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeechSynthesisOptions(speed: 0.24);
    }

    #[Test]
    public function constructorRejectsSpeedAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeechSynthesisOptions(speed: 4.1);
    }

    #[Test]
    public function constructorAcceptsNullValues(): void
    {
        $options = new SpeechSynthesisOptions(
            model: null,
            voice: null,
            format: null,
            speed: null,
        );

        self::assertNull($options->model);
        self::assertNull($options->voice);
        self::assertNull($options->format);
        self::assertNull($options->speed);
    }

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $options = new SpeechSynthesisOptions(
            model: 'tts-1-hd',
            voice: 'nova',
            format: 'flac',
            speed: 1.2,
        );

        $array = $options->toArray();

        self::assertEquals('tts-1-hd', $array['model']);
        self::assertEquals('nova', $array['voice']);
        self::assertEquals('flac', $array['response_format']);
        self::assertEquals(1.2, $array['speed']);
    }

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new SpeechSynthesisOptions(
            model: 'tts-1',
            voice: null,
            format: null,
            speed: null,
        );

        $array = $options->toArray();

        self::assertArrayHasKey('model', $array);
        self::assertArrayNotHasKey('voice', $array);
        self::assertArrayNotHasKey('response_format', $array);
        self::assertArrayNotHasKey('speed', $array);
    }

    #[Test]
    public function fromArrayCreatesOptionsFromArray(): void
    {
        $options = SpeechSynthesisOptions::fromArray([
            'model' => 'tts-1-hd',
            'voice' => 'shimmer',
            'format' => 'opus',
            'speed' => 2.0,
        ]);

        self::assertEquals('tts-1-hd', $options->model);
        self::assertEquals('shimmer', $options->voice);
        self::assertEquals('opus', $options->format);
        self::assertEquals(2.0, $options->speed);
    }

    #[Test]
    public function fromArrayAcceptsResponseFormatAlias(): void
    {
        $options = SpeechSynthesisOptions::fromArray([
            'response_format' => 'wav',
        ]);

        self::assertEquals('wav', $options->format);
    }

    #[Test]
    public function fromArrayHandlesMissingValues(): void
    {
        $options = SpeechSynthesisOptions::fromArray([]);

        self::assertNull($options->model);
        self::assertNull($options->voice);
        self::assertNull($options->format);
        self::assertNull($options->speed);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $options = SpeechSynthesisOptions::fromArray([
            'model' => 123,
            'voice' => ['array'],
            'speed' => 'not_a_number',
        ]);

        self::assertNull($options->model);
        self::assertNull($options->voice);
        self::assertNull($options->speed);
    }

    #[Test]
    public function fromArrayAcceptsIntegerSpeed(): void
    {
        $options = SpeechSynthesisOptions::fromArray([
            'speed' => 2,
        ]);

        self::assertEquals(2.0, $options->speed);
    }

    #[Test]
    public function hdFactoryCreatesHdOptions(): void
    {
        $options = SpeechSynthesisOptions::hd();

        self::assertEquals('tts-1-hd', $options->model);
        self::assertEquals('alloy', $options->voice);
    }

    #[Test]
    public function hdFactoryAcceptsCustomVoice(): void
    {
        $options = SpeechSynthesisOptions::hd('nova');

        self::assertEquals('tts-1-hd', $options->model);
        self::assertEquals('nova', $options->voice);
    }

    #[Test]
    public function fastFactoryCreatesFastOptions(): void
    {
        $options = SpeechSynthesisOptions::fast();

        self::assertEquals('tts-1', $options->model);
        self::assertEquals('alloy', $options->voice);
    }

    #[Test]
    public function fastFactoryAcceptsCustomVoice(): void
    {
        $options = SpeechSynthesisOptions::fast('echo');

        self::assertEquals('tts-1', $options->model);
        self::assertEquals('echo', $options->voice);
    }

    #[Test]
    public function getAvailableVoicesReturnsAllVoices(): void
    {
        $voices = SpeechSynthesisOptions::getAvailableVoices();

        self::assertCount(6, $voices);
        self::assertContains('alloy', $voices);
        self::assertContains('echo', $voices);
        self::assertContains('fable', $voices);
        self::assertContains('onyx', $voices);
        self::assertContains('nova', $voices);
        self::assertContains('shimmer', $voices);
    }
}
