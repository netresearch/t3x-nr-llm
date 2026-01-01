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

        self::assertFalse($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsFalseForEmptyArray(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        self::assertFalse($result->hasSegments());
    }

    #[Test]
    public function hasSegmentsReturnsTrueForNonEmptyArray(): void
    {
        $segment = new Segment('Hello', 0.0, 1.0);
        $result = new TranscriptionResult('Test', 'en', segments: [$segment]);

        self::assertTrue($result->hasSegments());
    }

    #[Test]
    public function getFormattedDurationReturnsNullWhenDurationIsNull(): void
    {
        $result = new TranscriptionResult('Test', 'en', duration: null);

        self::assertNull($result->getFormattedDuration());
    }

    #[Test]
    #[DataProvider('durationFormattingProvider')]
    public function getFormattedDurationFormatsCorrectly(float $duration, string $expected): void
    {
        $result = new TranscriptionResult('Test', 'en', duration: $duration);

        self::assertEquals($expected, $result->getFormattedDuration());
    }

    /**
     * @return array<string, array{float, string}>
     */
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

        self::assertNull($result->getConfidencePercent());
    }

    #[Test]
    #[DataProvider('confidenceFormattingProvider')]
    public function getConfidencePercentFormatsCorrectly(float $confidence, string $expected): void
    {
        $result = new TranscriptionResult('Test', 'en', confidence: $confidence);

        self::assertEquals($expected, $result->getConfidencePercent());
    }

    /**
     * @return array<string, array{float, string}>
     */
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

        self::assertEquals($expected, $result->getWordCount());
    }

    /**
     * @return array<string, array{string, int}>
     */
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

        self::assertNull($result->toSrt());
    }

    #[Test]
    public function toSrtReturnsNullWithEmptySegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        self::assertNull($result->toSrt());
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

        self::assertNotNull($srt);
        self::assertStringContainsString("1\n", $srt);
        self::assertStringContainsString("2\n", $srt);
        self::assertStringContainsString('00:00:00,000 --> 00:00:02,500', $srt);
        self::assertStringContainsString('00:00:02,500 --> 00:00:05,000', $srt);
        self::assertStringContainsString('First segment', $srt);
        self::assertStringContainsString('Second segment', $srt);
    }

    #[Test]
    #[DataProvider('srtTimeFormattingProvider')]
    public function toSrtFormatsTimesCorrectly(float $start, float $end, string $expectedStart, string $expectedEnd): void
    {
        $segments = [new Segment('Test', $start, $end)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        self::assertNotNull($srt);
        self::assertStringContainsString($expectedStart . ' --> ' . $expectedEnd, $srt);
    }

    /**
     * @return array<string, array{float, float, string, string}>
     */
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

        self::assertNull($result->toVtt());
    }

    #[Test]
    public function toVttReturnsNullWithEmptySegments(): void
    {
        $result = new TranscriptionResult('Test', 'en', segments: []);

        self::assertNull($result->toVtt());
    }

    #[Test]
    public function toVttStartsWithWebvttHeader(): void
    {
        $segments = [new Segment('Test', 0.0, 1.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        self::assertNotNull($vtt);
        self::assertStringStartsWith("WEBVTT\n\n", $vtt);
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

        self::assertNotNull($vtt);
        self::assertStringContainsString('00:00:00.000 --> 00:00:01.000', $vtt);
        self::assertStringContainsString('00:00:01.000 --> 00:00:02.000', $vtt);
        self::assertStringContainsString('First', $vtt);
        self::assertStringContainsString('Second', $vtt);
    }

    #[Test]
    #[DataProvider('vttTimeFormattingProvider')]
    public function toVttFormatsTimesCorrectly(float $start, float $end, string $expectedStart, string $expectedEnd): void
    {
        $segments = [new Segment('Test', $start, $end)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        self::assertNotNull($vtt);
        self::assertStringContainsString($expectedStart . ' --> ' . $expectedEnd, $vtt);
    }

    /**
     * @return array<string, array{float, float, string, string}>
     */
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

        self::assertNotNull($srt);
        self::assertNotNull($vtt);
        // SRT uses comma for milliseconds
        self::assertStringContainsString(',500', $srt);
        // VTT uses period for milliseconds
        self::assertStringContainsString('.500', $vtt);
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

        self::assertNotNull($srt);
        $lines = explode("\n", $srt);

        // Find index lines (first line of each subtitle block)
        $indices = array_filter($lines, static fn(string $line): bool => preg_match('/^\d+$/', $line) === 1);
        $indices = array_values($indices);

        self::assertEquals('1', $indices[0]);
        self::assertEquals('2', $indices[1]);
        self::assertEquals('3', $indices[2]);
    }

    #[Test]
    public function getFormattedDurationHandlesExactMinuteBoundary(): void
    {
        // Test exact minute boundary (60 seconds)
        $result = new TranscriptionResult('Test', 'en', duration: 60.0);
        self::assertEquals('1:00', $result->getFormattedDuration());

        // Test just under minute boundary
        $result = new TranscriptionResult('Test', 'en', duration: 59.0);
        self::assertEquals('0:59', $result->getFormattedDuration());

        // Test just over minute boundary
        $result = new TranscriptionResult('Test', 'en', duration: 61.0);
        self::assertEquals('1:01', $result->getFormattedDuration());
    }

    #[Test]
    public function toSrtHandlesHourBoundaryCorrectly(): void
    {
        // Test exactly at hour boundary (3600 seconds)
        $segments = [new Segment('Hour mark', 3600.0, 3601.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        self::assertNotNull($srt);
        self::assertStringContainsString('01:00:00,000', $srt);
    }

    #[Test]
    public function toSrtHandlesModuloOperationsCorrectly(): void
    {
        // Test at 1 hour, 1 minute, 1 second (3661 seconds)
        $segments = [new Segment('Test', 3661.0, 3662.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $srt = $result->toSrt();

        self::assertNotNull($srt);
        self::assertStringContainsString('01:01:01,000', $srt);
    }

    #[Test]
    public function toVttHandlesModuloOperationsCorrectly(): void
    {
        // Test at 1 hour, 1 minute, 1 second (3661 seconds)
        $segments = [new Segment('Test', 3661.0, 3662.0)];
        $result = new TranscriptionResult('Test', 'en', segments: $segments);

        $vtt = $result->toVtt();

        self::assertNotNull($vtt);
        self::assertStringContainsString('01:01:01.000', $vtt);
    }

    #[Test]
    public function confidenceMultiplicationIsCorrect(): void
    {
        // Specifically test the multiplication by 100
        $result = new TranscriptionResult('Test', 'en', confidence: 0.123);

        self::assertEquals('12.3%', $result->getConfidencePercent());
    }

    #[Test]
    public function durationModuloSixtyIsCorrect(): void
    {
        // Test that seconds modulo 60 works correctly
        // 125 seconds = 2 minutes, 5 seconds
        $result = new TranscriptionResult('Test', 'en', duration: 125.0);

        self::assertEquals('2:05', $result->getFormattedDuration());
    }
}
