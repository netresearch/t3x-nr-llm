<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\Segment;
use Netresearch\NrLlm\Specialized\Speech\Word;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for Segment.
 */
#[CoversClass(Segment::class)]
class SegmentMutationTest extends AbstractUnitTestCase
{
    #[Test]
    public function getDurationReturnsEndMinusStart(): void
    {
        $segment = new Segment('Test', 1.5, 4.5);

        self::assertEquals(3.0, $segment->getDuration());
    }

    #[Test]
    public function getDurationHandlesZeroDuration(): void
    {
        $segment = new Segment('Test', 2.0, 2.0);

        self::assertEquals(0.0, $segment->getDuration());
    }

    #[Test]
    public function hasWordsReturnsFalseForNull(): void
    {
        $segment = new Segment('Test', 0.0, 1.0, words: null);

        self::assertFalse($segment->hasWords());
    }

    #[Test]
    public function hasWordsReturnsFalseForEmptyArray(): void
    {
        $segment = new Segment('Test', 0.0, 1.0, words: []);

        self::assertFalse($segment->hasWords());
    }

    #[Test]
    public function hasWordsReturnsTrueForNonEmptyArray(): void
    {
        $word = new Word('Test', 0.0, 0.5);
        $segment = new Segment('Test', 0.0, 1.0, words: [$word]);

        self::assertTrue($segment->hasWords());
    }

    #[Test]
    public function fromWhisperResponseCreatesSegment(): void
    {
        $data = [
            'text' => 'Hello world',
            'start' => 0.5,
            'end' => 2.5,
        ];

        $segment = Segment::fromWhisperResponse($data);

        self::assertEquals('Hello world', $segment->text);
        self::assertEquals(0.5, $segment->start);
        self::assertEquals(2.5, $segment->end);
    }

    #[Test]
    public function fromWhisperResponseUsesDefaultsForMissingKeys(): void
    {
        $data = [];

        $segment = Segment::fromWhisperResponse($data);

        self::assertEquals('', $segment->text);
        self::assertEquals(0.0, $segment->start);
        self::assertEquals(0.0, $segment->end);
        self::assertNull($segment->confidence);
        self::assertNull($segment->words);
    }

    #[Test]
    public function fromWhisperResponseParsesWords(): void
    {
        $data = [
            'text' => 'Hello',
            'start' => 0.0,
            'end' => 1.0,
            'words' => [
                ['word' => 'Hello', 'start' => 0.0, 'end' => 0.5],
            ],
        ];

        $segment = Segment::fromWhisperResponse($data);

        self::assertTrue($segment->hasWords());
        self::assertNotNull($segment->words);
        self::assertCount(1, $segment->words);
    }

    #[Test]
    public function fromWhisperResponseCalculatesConfidenceFromLogProb(): void
    {
        $data = [
            'text' => 'Test',
            'start' => 0.0,
            'end' => 1.0,
            'avg_logprob' => -0.5,
        ];

        $segment = Segment::fromWhisperResponse($data);

        // exp(-0.5) ≈ 0.6065
        self::assertNotNull($segment->confidence);
        self::assertEqualsWithDelta(0.6065, $segment->confidence, 0.001);
    }

    #[Test]
    #[DataProvider('durationProvider')]
    public function getDurationCalculatesCorrectly(float $start, float $end, float $expected): void
    {
        $segment = new Segment('Test', $start, $end);

        self::assertEquals($expected, $segment->getDuration());
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function durationProvider(): array
    {
        return [
            'simple' => [0.0, 1.0, 1.0],
            'non-zero start' => [1.5, 3.5, 2.0],
            'same values' => [5.0, 5.0, 0.0],
            'decimal precision' => [0.123, 0.456, 0.333],
        ];
    }
}
