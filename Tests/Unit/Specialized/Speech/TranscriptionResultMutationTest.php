<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\Segment;
use Netresearch\NrLlm\Specialized\Speech\TranscriptionResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for TranscriptionResult.
 */
#[CoversClass(TranscriptionResult::class)]
class TranscriptionResultMutationTest extends AbstractUnitTestCase
{
    #[Test]
    public function hasSegmentsReturnsFalseForNull(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: null);

        $this->assertFalse($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsFalseForEmptyArray(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        $this->assertFalse($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsTrueForNonEmptyArray(): void
    {
        $segment = new Segment('Hello', 0.0, 1.0);
        $result = new TranscriptionResult('Test', 'en', segments: [$segment]);

        $this->assertTrue($result->hasSegments());
    }

    #[Test]
    public function getFormattedDurationReturnsNullWhenDurationIsNull(): void
    {
        $result = new TranscriptionResult('Test', 'en', duration: null);

        $this->assertNull($result->getFormattedDuration());
    }

    #[Test]
    #[DataProvider('durationFormattingProvider')]
    public function getFormattedDurationFormatsCorrectly(float $duration, string $expected): void
    {
        $result = new TranscriptionResult('Test', 'en', duration: $duration);

        $this->assertEquals($expected, $result->getFormattedDuration());
    }

    public static function durationFormattingProvider(): array
    {
        return [
            'zero seconds' => [0.0, '0:00'],
            'one second' => [1.0, '0:01'],
            'thirty seconds' => [30.0, '0:30'],
            'one minute' => [60.0, '1:00'],
            'one minute thirty' => [90.0, '1:30'],
            'two minutes' => [120.0, '2:00'],
            'ten minutes' => [600.0, '10:00'],
            'one hour' => [3600.0, '60:00'],
            'with decimals' => [65.5, '1:05'],
        ];
    }

    #[Test]
    public function getConfidencePercentReturnsNullWhenConfidenceIsNull(): void
    {
        $result = new TranscriptionResult('Test', 'en', confidence: null);

        $this->assertNull($result->getConfidencePercent());
    }

    #[Test]
    #[DataProvider('confidenceFormattingProvider')]
    public function getConfidencePercentFormatsCorrectly(float $confidence, string $expected): void
    {
        $result = new TranscriptionResult('Test', 'en', confidence: $confidence);

        $this->assertEquals($expected, $result->getConfidencePercent());
    }

    public static function confidenceFormattingProvider(): array
    {
        return [
            'zero percent' => [0.0, '0.0%'],
            'fifty percent' => [0.5, '50.0%'],
            'hundred percent' => [1.0, '100.0%'],
            'ninety five percent' => [0.95, '95.0%'],
            'with decimals' => [0.876, '87.6%'],
        ];
    }

    #[Test]
    #[DataProvider('wordCountProvider')]
    public function getWordCountReturnsCorrectCount(string $text, int $expected): void
    {
        $result = new TranscriptionResult($text, 'en');

        $this->assertEquals($expected, $result->getWordCount());
    }

    public static function wordCountProvider(): array
    {
        return [
            'empty string' => ['', 0],
            'one word' => ['Hello', 1],
            'two words' => ['Hello World', 2],
            'multiple words' => ['The quick brown fox', 4],
            'with punctuation' => ['Hello, world!', 2],
        ];
    }

    #[Test]
    public function toSrtReturnsNullWithoutSegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: null);

        $this->assertNull($result->toSrt());
    }

    #[Test]
    public function toSrtReturnsNullWithEmptySegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        $this->assertNull($result->toSrt());
    }

    #[Test]
    public function toSrtFormatsSegmentsCorrectly(): void
    {
        $segments = [
            new Segment('First segment', 0.0, 2.5),
            new Segment('Second segment', 2.5, 5.0),
        ];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        $this->assertNotNull($srt);
        $this->assertStringContainsString("1\n", $srt);
        $this->assertStringContainsString("2\n", $srt);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:02,500', $srt);
        $this->assertStringContainsString('00:00:02,500 --> 00:00:05,000', $srt);
        $this->assertStringContainsString('First segment', $srt);
        $this->assertStringContainsString('Second segment', $srt);
    }

    #[Test]
    #[DataProvider('srtTimeFormattingProvider')]
    public function toSrtFormatsTimesCorrectly(float $start, float $end, string $expectedStart, string $expectedEnd): void
    {
        $segments = [new Segment('Test', $start, $end)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        $this->assertNotNull($srt);
        $this->assertStringContainsString($expectedStart . ' --> ' . $expectedEnd, $srt);
    }

    public static function srtTimeFormattingProvider(): array
    {
        return [
            'zero to one second' => [0.0, 1.0, '00:00:00,000', '00:00:01,000'],
            'with milliseconds' => [0.0, 1.5, '00:00:00,000', '00:00:01,500'],
            'one minute' => [60.0, 61.0, '00:01:00,000', '00:01:01,000'],
            'one hour' => [3600.0, 3601.0, '01:00:00,000', '01:00:01,000'],
            'complex time' => [3661.5, 3722.75, '01:01:01,500', '01:02:02,750'],
            'two hours plus' => [7325.123, 7326.456, '02:02:05,123', '02:02:06,456'],
        ];
    }

    #[Test]
    public function toVttReturnsNullWithoutSegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: null);

        $this->assertNull($result->toVtt());
    }

    #[Test]
    public function toVttReturnsNullWithEmptySegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        $this->assertNull($result->toVtt());
    }

    #[Test]
    public function toVttStartsWithWebvttHeader(): void
    {
        $segments = [new Segment('Test', 0.0, 1.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        $this->assertNotNull($vtt);
        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
    }

    #[Test]
    public function toVttFormatsSegmentsCorrectly(): void
    {
        $segments = [
            new Segment('First', 0.0, 1.0),
            new Segment('Second', 1.0, 2.0),
        ];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        $this->assertNotNull($vtt);
        $this->assertStringContainsString('00:00:00.000 --> 00:00:01.000', $vtt);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.000', $vtt);
        $this->assertStringContainsString('First', $vtt);
        $this->assertStringContainsString('Second', $vtt);
    }

    #[Test]
    #[DataProvider('vttTimeFormattingProvider')]
    public function toVttFormatsTimesCorrectly(float $start, float $end, string $expectedStart, string $expectedEnd): void
    {
        $segments = [new Segment('Test', $start, $end)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        $this->assertNotNull($vtt);
        $this->assertStringContainsString($expectedStart . ' --> ' . $expectedEnd, $vtt);
    }

    public static function vttTimeFormattingProvider(): array
    {
        return [
            'zero to one second' => [0.0, 1.0, '00:00:00.000', '00:00:01.000'],
            'with milliseconds' => [0.0, 1.5, '00:00:00.000', '00:00:01.500'],
            'one minute' => [60.0, 61.0, '00:01:00.000', '00:01:01.000'],
            'one hour' => [3600.0, 3601.0, '01:00:00.000', '01:00:01.000'],
            'complex time' => [3661.5, 3722.75, '01:01:01.500', '01:02:02.750'],
            'fifty nine minutes' => [3540.0, 3599.0, '00:59:00.000', '00:59:59.000'],
        ];
    }

    #[Test]
    public function srtAndVttDifferInTimeSeparator(): void
    {
        $segments = [new Segment('Test', 1.5, 2.5)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();
        $vtt = $result->toVtt();

        $this->assertNotNull($srt);
        $this->assertNotNull($vtt);
        // SRT uses comma for milliseconds
        $this->assertStringContainsString(',500', $srt);
        // VTT uses period for milliseconds
        $this->assertStringContainsString('.500', $vtt);
    }

    #[Test]
    public function toSrtIncrementsIndexForEachSegment(): void
    {
        $segments = [
            new Segment('One', 0.0, 1.0),
            new Segment('Two', 1.0, 2.0),
            new Segment('Three', 2.0, 3.0),
        ];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        $this->assertNotNull($srt);
        $lines = explode("\n", $srt);

        // Find index lines (first line of each subtitle block)
        $indices = array_filter($lines, fn($line) => preg_match('/^\d+$/', $line));
        $indices = array_values($indices);

        $this->assertEquals('1', $indices[0]);
        $this->assertEquals('2', $indices[1]);
        $this->assertEquals('3', $indices[2]);
    }

    #[Test]
    public function getFormattedDurationHandlesExactMinuteBoundary(): void
    {
        // Test exact minute boundary (60 seconds)
        $result = new TranscriptionResult('Test', 'en', duration: 60.0);
        $this->assertEquals('1:00', $result->getFormattedDuration());

        // Test just under minute boundary
        $result = new TranscriptionResult('Test', 'en', duration: 59.0);
        $this->assertEquals('0:59', $result->getFormattedDuration());

        // Test just over minute boundary
        $result = new TranscriptionResult('Test', 'en', duration: 61.0);
        $this->assertEquals('1:01', $result->getFormattedDuration());
    }

    #[Test]
    public function toSrtHandlesHourBoundaryCorrectly(): void
    {
        // Test exactly at hour boundary (3600 seconds)
        $segments = [new Segment('Hour mark', 3600.0, 3601.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        $this->assertNotNull($srt);
        $this->assertStringContainsString('01:00:00,000', $srt);
    }

    #[Test]
    public function toSrtHandlesModuloOperationsCorrectly(): void
    {
        // Test at 1 hour, 1 minute, 1 second (3661 seconds)
        $segments = [new Segment('Test', 3661.0, 3662.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        $this->assertNotNull($srt);
        $this->assertStringContainsString('01:01:01,000', $srt);
    }

    #[Test]
    public function toVttHandlesModuloOperationsCorrectly(): void
    {
        // Test at 1 hour, 1 minute, 1 second (3661 seconds)
        $segments = [new Segment('Test', 3661.0, 3662.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        $this->assertNotNull($vtt);
        $this->assertStringContainsString('01:01:01.000', $vtt);
    }

    #[Test]
    public function confidenceMultiplicationIsCorrect(): void
    {
        // Specifically test the multiplication by 100
        $result = new TranscriptionResult('Test', 'en', confidence: 0.123);

        $this->assertEquals('12.3%', $result->getConfidencePercent());
    }

    #[Test]
    public function durationModuloSixtyIsCorrect(): void
    {
        // Test that seconds modulo 60 works correctly
        // 125 seconds = 2 minutes, 5 seconds
        $result = new TranscriptionResult('Test', 'en', duration: 125.0);

        $this->assertEquals('2:05', $result->getFormattedDuration());
    }
}
