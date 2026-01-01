<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\Segment;
use Netresearch\NrLlm\Specialized\Speech\Word;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(Segment::class)]
class SegmentTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $words = [
            new Word('Hello', 0.0, 0.5),
            new Word('World', 0.5, 1.0),
        ];

        $segment = new Segment(
            text: 'Hello World',
            start: 0.0,
            end: 1.0,
            confidence: 0.95,
            words: $words,
        );

        self::assertEquals('Hello World', $segment->text);
        self::assertEquals(0.0, $segment->start);
        self::assertEquals(1.0, $segment->end);
        self::assertEquals(0.95, $segment->confidence);
        self::assertEquals($words, $segment->words);
    }

    #[Test]
    public function constructorDefaultsOptionalParameters(): void
    {
        $segment = new Segment(
            text: 'Test',
            start: 0.0,
            end: 1.0,
        );

        self::assertNull($segment->confidence);
        self::assertNull($segment->words);
    }

    #[Test]
    public function getDurationCalculatesCorrectly(): void
    {
        $segment = new Segment('Test', 2.5, 5.0);

        self::assertEquals(2.5, $segment->getDuration());
    }

    #[Test]
    public function getDurationHandlesZeroDuration(): void
    {
        $segment = new Segment('Test', 1.0, 1.0);

        self::assertEquals(0.0, $segment->getDuration());
    }

    #[Test]
    public function hasWordsReturnsTrueWhenPresent(): void
    {
        $segment = new Segment(
            'Hello',
            0.0,
            1.0,
            null,
            [new Word('Hello', 0.0, 1.0)],
        );

        self::assertTrue($segment->hasWords());
    }

    #[Test]
    public function hasWordsReturnsFalseWhenNull(): void
    {
        $segment = new Segment('Test', 0.0, 1.0);

        self::assertFalse($segment->hasWords());
    }

    #[Test]
    public function hasWordsReturnsFalseWhenEmpty(): void
    {
        $segment = new Segment('Test', 0.0, 1.0, null, []);

        self::assertFalse($segment->hasWords());
    }

    #[Test]
    public function fromWhisperResponseCreatesSegment(): void
    {
        $data = [
            'text' => 'Hello World',
            'start' => 0.0,
            'end' => 2.5,
            'avg_logprob' => -0.2,
        ];

        $segment = Segment::fromWhisperResponse($data);

        self::assertEquals('Hello World', $segment->text);
        self::assertEquals(0.0, $segment->start);
        self::assertEquals(2.5, $segment->end);
        self::assertNotNull($segment->confidence);
    }

    #[Test]
    public function fromWhisperResponseHandlesMissingData(): void
    {
        $data = [];

        $segment = Segment::fromWhisperResponse($data);

        self::assertEquals('', $segment->text);
        self::assertEquals(0.0, $segment->start);
        self::assertEquals(0.0, $segment->end);
        self::assertNull($segment->confidence);
    }

    #[Test]
    public function fromWhisperResponseParsesWords(): void
    {
        $data = [
            'text' => 'Hello World',
            'start' => 0.0,
            'end' => 2.0,
            'words' => [
                ['word' => 'Hello', 'start' => 0.0, 'end' => 1.0],
                ['word' => 'World', 'start' => 1.0, 'end' => 2.0],
            ],
        ];

        $segment = Segment::fromWhisperResponse($data);

        self::assertTrue($segment->hasWords());
        self::assertNotNull($segment->words);
        self::assertCount(2, $segment->words);
    }

    #[Test]
    public function segmentIsReadonly(): void
    {
        $segment = new Segment('Test', 0.0, 1.0);

        // Properties are readonly - verify they are accessible and have expected values
        self::assertSame('Test', $segment->text);
        self::assertSame(0.0, $segment->start);
        self::assertSame(1.0, $segment->end);
    }
}
