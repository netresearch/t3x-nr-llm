<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\Segment;
use Netresearch\NrLlm\Specialized\Speech\TranscriptionResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranscriptionResult::class)]
class TranscriptionResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $segments = [
            new Segment('Hello', 0.0, 2.5),
            new Segment('World', 2.5, 5.0),
        ];
        $metadata = ['file' => 'audio.mp3'];

        $result = new TranscriptionResult(
            text: 'Hello World',
            language: 'en',
            duration: 5.0,
            segments: $segments,
            confidence: 0.95,
            metadata: $metadata,
        );

        $this->assertEquals('Hello World', $result->text);
        $this->assertEquals('en', $result->language);
        $this->assertEquals(5.0, $result->duration);
        $this->assertEquals($segments, $result->segments);
        $this->assertEquals(0.95, $result->confidence);
        $this->assertEquals($metadata, $result->metadata);
    }

    #[Test]
    public function constructorAcceptsMinimalParameters(): void
    {
        $result = new TranscriptionResult(
            text: 'Test transcription',
            language: 'de',
        );

        $this->assertEquals('Test transcription', $result->text);
        $this->assertEquals('de', $result->language);
        $this->assertNull($result->duration);
        $this->assertNull($result->segments);
        $this->assertNull($result->confidence);
        $this->assertNull($result->metadata);
    }

    #[Test]
    public function hasSegmentsReturnsTrueWhenPresent(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello',
            language: 'en',
            segments: [new Segment('Hello', 0.0, 1.0)],
        );

        $this->assertTrue($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsFalseWhenNull(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello',
            language: 'en',
            segments: null,
        );

        $this->assertFalse($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsFalseWhenEmpty(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello',
            language: 'en',
            segments: [],
        );

        $this->assertFalse($result->hasSegments());
    }

    #[Test]
    #[DataProvider('durationFormattingProvider')]
    public function getFormattedDurationFormatsCorrectly(float $duration, string $expected): void
    {
        $result = new TranscriptionResult(
            text: 'test',
            language: 'en',
            duration: $duration,
        );

        $this->assertEquals($expected, $result->getFormattedDuration());
    }

    public static function durationFormattingProvider(): array
    {
        return [
            'zero seconds' => [0.0, '0:00'],
            'under a minute' => [45.0, '0:45'],
            'one minute' => [60.0, '1:00'],
            'mixed' => [154.0, '2:34'],
            'long audio' => [3725.0, '62:05'],
        ];
    }

    #[Test]
    public function getFormattedDurationReturnsNullWhenNoDuration(): void
    {
        $result = new TranscriptionResult(
            text: 'test',
            language: 'en',
            duration: null,
        );

        $this->assertNull($result->getFormattedDuration());
    }

    #[Test]
    #[DataProvider('confidencePercentProvider')]
    public function getConfidencePercentFormatsCorrectly(float $confidence, string $expected): void
    {
        $result = new TranscriptionResult(
            text: 'test',
            language: 'en',
            confidence: $confidence,
        );

        $this->assertEquals($expected, $result->getConfidencePercent());
    }

    public static function confidencePercentProvider(): array
    {
        return [
            'high confidence' => [0.95, '95.0%'],
            'perfect confidence' => [1.0, '100.0%'],
            'low confidence' => [0.5, '50.0%'],
            'zero confidence' => [0.0, '0.0%'],
            // number_format uses banker's rounding: 87.65 rounds to 87.6
            'precise value' => [0.8765, '87.6%'],
        ];
    }

    #[Test]
    public function getConfidencePercentReturnsNullWhenNoConfidence(): void
    {
        $result = new TranscriptionResult(
            text: 'test',
            language: 'en',
            confidence: null,
        );

        $this->assertNull($result->getConfidencePercent());
    }

    #[Test]
    public function getWordCountReturnsCorrectCount(): void
    {
        $result = new TranscriptionResult(
            text: 'The quick brown fox jumps over the lazy dog',
            language: 'en',
        );

        $this->assertEquals(9, $result->getWordCount());
    }

    #[Test]
    public function getWordCountReturnsZeroForEmptyText(): void
    {
        $result = new TranscriptionResult(
            text: '',
            language: 'en',
        );

        $this->assertEquals(0, $result->getWordCount());
    }

    #[Test]
    public function toSrtReturnsNullWhenNoSegments(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello world',
            language: 'en',
            segments: null,
        );

        $this->assertNull($result->toSrt());
    }

    #[Test]
    public function toSrtReturnsNullWhenEmptySegments(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello world',
            language: 'en',
            segments: [],
        );

        $this->assertNull($result->toSrt());
    }

    #[Test]
    public function toSrtFormatsCorrectly(): void
    {
        $segments = [
            new Segment('Hello', 0.0, 2.5),
            new Segment('World', 2.5, 5.123),
        ];

        $result = new TranscriptionResult(
            text: 'Hello World',
            language: 'en',
            segments: $segments,
        );

        $srt = $result->toSrt();

        $this->assertStringContainsString('1', $srt);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:02,500', $srt);
        $this->assertStringContainsString('Hello', $srt);
        $this->assertStringContainsString('2', $srt);
        $this->assertStringContainsString('00:00:02,500 --> 00:00:05,123', $srt);
        $this->assertStringContainsString('World', $srt);
    }

    #[Test]
    public function toVttReturnsNullWhenNoSegments(): void
    {
        $result = new TranscriptionResult(
            text: 'Hello',
            language: 'en',
            segments: null,
        );

        $this->assertNull($result->toVtt());
    }

    #[Test]
    public function toVttStartsWithHeader(): void
    {
        $segments = [new Segment('Test', 0.0, 1.0)];

        $result = new TranscriptionResult(
            text: 'Test',
            language: 'en',
            segments: $segments,
        );

        $vtt = $result->toVtt();

        $this->assertStringStartsWith('WEBVTT', $vtt);
    }

    #[Test]
    public function toVttFormatsTimeWithDots(): void
    {
        $segments = [new Segment('Hello', 0.0, 2.5)];

        $result = new TranscriptionResult(
            text: 'Hello',
            language: 'en',
            segments: $segments,
        );

        $vtt = $result->toVtt();

        // VTT uses dots instead of commas for milliseconds
        $this->assertStringContainsString('00:00:00.000 --> 00:00:02.500', $vtt);
    }

    #[Test]
    public function srtTimeFormattingHandlesLongDurations(): void
    {
        $segments = [
            new Segment('After one hour', 3665.123, 3670.456), // 1:01:05.123 -> 1:01:10.456
        ];

        $result = new TranscriptionResult(
            text: 'After one hour',
            language: 'en',
            segments: $segments,
        );

        $srt = $result->toSrt();

        $this->assertStringContainsString('01:01:05,123 --> 01:01:10,456', $srt);
    }

    #[Test]
    public function multipleSegmentsAreNumberedSequentially(): void
    {
        $segments = [
            new Segment('One', 0.0, 1.0),
            new Segment('Two', 1.0, 2.0),
            new Segment('Three', 2.0, 3.0),
        ];

        $result = new TranscriptionResult(
            text: 'One Two Three',
            language: 'en',
            segments: $segments,
        );

        $srt = $result->toSrt();
        $lines = explode("\n", $srt);

        // Check segment numbers appear at start of blocks
        $this->assertEquals('1', $lines[0]);
        $this->assertEquals('2', $lines[4]);
        $this->assertEquals('3', $lines[8]);
    }
}
