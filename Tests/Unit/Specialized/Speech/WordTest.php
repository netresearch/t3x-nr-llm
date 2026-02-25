<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\Word;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(Word::class)]
class WordTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $word = new Word(
            word: 'hello',
            start: 0.5,
            end: 1.2,
        );

        self::assertEquals('hello', $word->word);
        self::assertEquals(0.5, $word->start);
        self::assertEquals(1.2, $word->end);
    }

    #[Test]
    public function getDurationReturnsCorrectValue(): void
    {
        $word = new Word(
            word: 'test',
            start: 1.0,
            end: 2.5,
        );

        self::assertEquals(1.5, $word->getDuration());
    }

    #[Test]
    public function getDurationHandlesZeroDuration(): void
    {
        $word = new Word(
            word: 'instant',
            start: 1.0,
            end: 1.0,
        );

        self::assertEquals(0.0, $word->getDuration());
    }

    #[Test]
    public function fromWhisperResponseCreatesWord(): void
    {
        $data = [
            'word' => 'transcribed',
            'start' => 5.2,
            'end' => 6.8,
        ];

        $word = Word::fromWhisperResponse($data);

        self::assertEquals('transcribed', $word->word);
        self::assertEquals(5.2, $word->start);
        self::assertEquals(6.8, $word->end);
    }

    #[Test]
    public function fromWhisperResponseHandlesMissingValues(): void
    {
        $word = Word::fromWhisperResponse([]);

        self::assertEquals('', $word->word);
        self::assertEquals(0.0, $word->start);
        self::assertEquals(0.0, $word->end);
    }

    #[Test]
    public function fromWhisperResponseHandlesInvalidWordType(): void
    {
        $data = [
            'word' => 123,
            'start' => 1.0,
            'end' => 2.0,
        ];

        $word = Word::fromWhisperResponse($data);

        self::assertEquals('', $word->word);
    }

    #[Test]
    public function fromWhisperResponseHandlesIntegerTimings(): void
    {
        $data = [
            'word' => 'test',
            'start' => 5,
            'end' => 10,
        ];

        $word = Word::fromWhisperResponse($data);

        self::assertEquals(5.0, $word->start);
        self::assertEquals(10.0, $word->end);
    }

    #[Test]
    public function fromWhisperResponseHandlesInvalidTimingTypes(): void
    {
        $data = [
            'word' => 'test',
            'start' => 'invalid',
            'end' => ['array'],
        ];

        $word = Word::fromWhisperResponse($data);

        self::assertEquals(0.0, $word->start);
        self::assertEquals(0.0, $word->end);
    }
}
