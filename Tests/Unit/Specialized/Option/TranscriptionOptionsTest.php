<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use InvalidArgumentException;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranscriptionOptions::class)]
class TranscriptionOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $options = new TranscriptionOptions();

        self::assertEquals('whisper-1', $options->model);
        self::assertNull($options->language);
        self::assertEquals('json', $options->format);
        self::assertNull($options->prompt);
        self::assertNull($options->temperature);
    }

    #[Test]
    public function constructorAcceptsCustomValues(): void
    {
        $options = new TranscriptionOptions(
            model: 'whisper-1',
            language: 'en',
            format: 'verbose_json',
            prompt: 'Technical transcription',
            temperature: 0.5,
        );

        self::assertEquals('whisper-1', $options->model);
        self::assertEquals('en', $options->language);
        self::assertEquals('verbose_json', $options->format);
        self::assertEquals('Technical transcription', $options->prompt);
        self::assertEquals(0.5, $options->temperature);
    }

    #[Test]
    #[DataProvider('validFormatsProvider')]
    public function constructorAcceptsAllValidFormats(string $format): void
    {
        $options = new TranscriptionOptions(format: $format);

        self::assertEquals($format, $options->format);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFormatsProvider(): array
    {
        return [
            'json' => ['json'],
            'text' => ['text'],
            'srt' => ['srt'],
            'vtt' => ['vtt'],
            'verbose_json' => ['verbose_json'],
        ];
    }

    #[Test]
    public function constructorRejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::assertInstanceOf(TranscriptionOptions::class, new TranscriptionOptions(format: 'invalid_format'));
    }

    #[Test]
    public function constructorAcceptsValidTemperatureRange(): void
    {
        $min = new TranscriptionOptions(temperature: 0.0);
        $max = new TranscriptionOptions(temperature: 1.0);

        self::assertEquals(0.0, $min->temperature);
        self::assertEquals(1.0, $max->temperature);
    }

    #[Test]
    public function constructorRejectsTemperatureBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::assertInstanceOf(TranscriptionOptions::class, new TranscriptionOptions(temperature: -0.1));
    }

    #[Test]
    public function constructorRejectsTemperatureAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::assertInstanceOf(TranscriptionOptions::class, new TranscriptionOptions(temperature: 1.1));
    }

    #[Test]
    public function constructorAcceptsNullValues(): void
    {
        $options = new TranscriptionOptions(
            model: null,
            language: null,
            format: null,
            prompt: null,
            temperature: null,
        );

        self::assertNull($options->model);
        self::assertNull($options->language);
        self::assertNull($options->format);
        self::assertNull($options->prompt);
        self::assertNull($options->temperature);
    }

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $options = new TranscriptionOptions(
            model: 'whisper-1',
            language: 'de',
            format: 'srt',
            prompt: 'Medical transcription',
            temperature: 0.3,
        );

        $array = $options->toArray();

        self::assertEquals('whisper-1', $array['model']);
        self::assertEquals('de', $array['language']);
        self::assertEquals('srt', $array['response_format']);
        self::assertEquals('Medical transcription', $array['prompt']);
        self::assertEquals(0.3, $array['temperature']);
    }

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new TranscriptionOptions(
            model: 'whisper-1',
            language: null,
            format: 'json',
            prompt: null,
            temperature: null,
        );

        $array = $options->toArray();

        self::assertArrayHasKey('model', $array);
        self::assertArrayHasKey('response_format', $array);
        self::assertArrayNotHasKey('language', $array);
        self::assertArrayNotHasKey('prompt', $array);
        self::assertArrayNotHasKey('temperature', $array);
    }

    #[Test]
    public function fromArrayCreatesOptionsFromArray(): void
    {
        $options = TranscriptionOptions::fromArray([
            'model' => 'whisper-1',
            'language' => 'fr',
            'format' => 'vtt',
            'prompt' => 'Meeting notes',
            'temperature' => 0.7,
        ]);

        self::assertEquals('whisper-1', $options->model);
        self::assertEquals('fr', $options->language);
        self::assertEquals('vtt', $options->format);
        self::assertEquals('Meeting notes', $options->prompt);
        self::assertEquals(0.7, $options->temperature);
    }

    #[Test]
    public function fromArrayAcceptsResponseFormatAlias(): void
    {
        $options = TranscriptionOptions::fromArray([
            'response_format' => 'text',
        ]);

        self::assertEquals('text', $options->format);
    }

    #[Test]
    public function configurationDefaultsToNull(): void
    {
        $options = new TranscriptionOptions();

        self::assertNull($options->configuration);
    }

    #[Test]
    public function constructorAcceptsConfigurationIdentifier(): void
    {
        $options = new TranscriptionOptions(configuration: 'meeting-minutes');

        self::assertSame('meeting-minutes', $options->configuration);
    }

    #[Test]
    public function fromArrayReadsConfigurationIdentifier(): void
    {
        $options = TranscriptionOptions::fromArray(['configuration' => 'meeting-minutes']);

        self::assertSame('meeting-minutes', $options->configuration);
    }

    #[Test]
    public function toArrayOmitsConfiguration(): void
    {
        // `configuration` is consumer metadata for usage attribution,
        // not a transcription API parameter — it must never reach the
        // multipart payload.
        $options = new TranscriptionOptions(configuration: 'meeting-minutes');

        self::assertArrayNotHasKey('configuration', $options->toArray());
    }

    #[Test]
    public function fromArrayHandlesMissingValues(): void
    {
        $options = TranscriptionOptions::fromArray([]);

        self::assertNull($options->model);
        self::assertNull($options->language);
        self::assertNull($options->format);
        self::assertNull($options->prompt);
        self::assertNull($options->temperature);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $options = TranscriptionOptions::fromArray([
            'model' => 123,
            'language' => ['array'],
            'temperature' => 'not_a_number',
        ]);

        self::assertNull($options->model);
        self::assertNull($options->language);
        self::assertNull($options->temperature);
    }

    #[Test]
    public function fromArrayAcceptsIntegerTemperature(): void
    {
        $options = TranscriptionOptions::fromArray([
            'temperature' => 0,
        ]);

        self::assertEquals(0.0, $options->temperature);
    }

    #[Test]
    public function verboseFactoryCreatesVerboseOptions(): void
    {
        $options = TranscriptionOptions::verbose();

        self::assertEquals('verbose_json', $options->format);
        self::assertNull($options->language);
    }

    #[Test]
    public function verboseFactoryAcceptsLanguage(): void
    {
        $options = TranscriptionOptions::verbose('en');

        self::assertEquals('verbose_json', $options->format);
        self::assertEquals('en', $options->language);
    }

    #[Test]
    public function subtitlesFactoryCreatesSrtOptions(): void
    {
        $options = TranscriptionOptions::subtitles();

        self::assertEquals('srt', $options->format);
        self::assertNull($options->language);
    }

    #[Test]
    public function subtitlesFactoryAcceptsLanguage(): void
    {
        $options = TranscriptionOptions::subtitles('de');

        self::assertEquals('srt', $options->format);
        self::assertEquals('de', $options->language);
    }

    // ============ Budget / attribution fields (ADR-057) ============

    #[Test]
    public function constructorAcceptsBudgetFields(): void
    {
        $options = new TranscriptionOptions(beUserUid: 7, plannedCost: 0.42);

        self::assertSame(7, $options->getBeUserUid());
        self::assertSame(0.42, $options->getPlannedCost());
    }

    #[Test]
    public function fromArrayParsesBudgetFields(): void
    {
        $options = TranscriptionOptions::fromArray(['beUserUid' => 7, 'plannedCost' => 0.42]);

        self::assertSame(7, $options->getBeUserUid());
        self::assertSame(0.42, $options->getPlannedCost());
    }

    #[Test]
    public function constructorRejectsNegativeBeUserUid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('be_user_uid must be >= 0');

        self::assertInstanceOf(TranscriptionOptions::class, new TranscriptionOptions(beUserUid: -1));
    }

    #[Test]
    public function constructorRejectsNegativePlannedCost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('planned_cost must be >= 0.0');

        self::assertInstanceOf(TranscriptionOptions::class, new TranscriptionOptions(plannedCost: -0.01));
    }

    #[Test]
    public function withBeUserUidReturnsCloneWithFieldSet(): void
    {
        $original = new TranscriptionOptions();
        $modified = $original->withBeUserUid(11);

        self::assertNull($original->getBeUserUid());
        self::assertSame(11, $modified->getBeUserUid());
    }

    #[Test]
    public function budgetFieldsAreOmittedFromToArray(): void
    {
        // Attribution metadata, not wire payload: the service builds its
        // provider payload from toArray(), so a leak here would send the
        // uid to the remote API (ADR-057).
        $options = new TranscriptionOptions(beUserUid: 7, plannedCost: 0.5);

        $array = $options->toArray();

        self::assertArrayNotHasKey('beUserUid', $array);
        self::assertArrayNotHasKey('plannedCost', $array);
    }
}
