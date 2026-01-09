<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Speech\SpeechSynthesisResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SpeechSynthesisResult::class)]
class SpeechSynthesisResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $audioContent = 'binary audio data';
        $metadata = ['duration' => 5.2];

        $result = new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 100,
            metadata: $metadata,
        );

        self::assertEquals($audioContent, $result->audioContent);
        self::assertEquals('mp3', $result->format);
        self::assertEquals('tts-1', $result->model);
        self::assertEquals('alloy', $result->voice);
        self::assertEquals(100, $result->characterCount);
        self::assertEquals($metadata, $result->metadata);
    }

    #[Test]
    public function metadataDefaultsToNull(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertNull($result->metadata);
    }

    #[Test]
    public function getSizeReturnsContentLength(): void
    {
        $audioContent = str_repeat('x', 1024);

        $result = new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 50,
        );

        self::assertEquals(1024, $result->getSize());
    }

    #[Test]
    public function getFormattedSizeReturnsBytes(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: str_repeat('x', 500),
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 50,
        );

        self::assertEquals('500 bytes', $result->getFormattedSize());
    }

    #[Test]
    public function getFormattedSizeReturnsKilobytes(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: str_repeat('x', 2048),  // 2KB
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 50,
        );

        self::assertEquals('2.00 KB', $result->getFormattedSize());
    }

    #[Test]
    public function getFormattedSizeReturnsMegabytes(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: str_repeat('x', 1572864),  // 1.5MB
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 50,
        );

        self::assertEquals('1.50 MB', $result->getFormattedSize());
    }

    #[Test]
    #[DataProvider('mimeTypeProvider')]
    public function getMimeTypeReturnsCorrectType(string $format, string $expectedMime): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: $format,
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertEquals($expectedMime, $result->getMimeType());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mimeTypeProvider(): array
    {
        return [
            'mp3' => ['mp3', 'audio/mpeg'],
            'opus' => ['opus', 'audio/opus'],
            'aac' => ['aac', 'audio/aac'],
            'flac' => ['flac', 'audio/flac'],
            'wav' => ['wav', 'audio/wav'],
            'pcm' => ['pcm', 'audio/pcm'],
            'unknown' => ['xyz', 'application/octet-stream'],
        ];
    }

    #[Test]
    #[DataProvider('fileExtensionProvider')]
    public function getFileExtensionReturnsCorrectExtension(string $format, string $expectedExt): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: $format,
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertEquals($expectedExt, $result->getFileExtension());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fileExtensionProvider(): array
    {
        return [
            'mp3' => ['mp3', 'mp3'],
            'opus' => ['opus', 'opus'],
            'aac' => ['aac', 'aac'],
            'flac' => ['flac', 'flac'],
            'wav' => ['wav', 'wav'],
            'pcm' => ['pcm', 'pcm'],
            'unknown' => ['xyz', 'bin'],
        ];
    }

    #[Test]
    public function toBase64EncodesContent(): void
    {
        $audioContent = 'test audio content';

        $result = new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 18,
        );

        self::assertEquals(base64_encode($audioContent), $result->toBase64());
    }

    #[Test]
    public function toDataUrlReturnsFormattedUrl(): void
    {
        $audioContent = 'test audio';

        $result = new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 10,
        );

        $dataUrl = $result->toDataUrl();

        self::assertStringStartsWith('data:audio/mpeg;base64,', $dataUrl);
        self::assertStringContainsString(base64_encode($audioContent), $dataUrl);
    }

    #[Test]
    public function toDataUrlUsesCorrectMimeType(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: 'opus',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertStringStartsWith('data:audio/opus;base64,', $result->toDataUrl());
    }

    #[Test]
    public function isHdReturnsTrueForHdModel(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: 'mp3',
            model: 'tts-1-hd',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertTrue($result->isHd());
    }

    #[Test]
    public function isHdReturnsFalseForStandardModel(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        self::assertFalse($result->isHd());
    }

    #[Test]
    public function saveToFileWritesContent(): void
    {
        $audioContent = 'test audio content for file';
        $tempFile = sys_get_temp_dir() . '/test_audio_' . uniqid() . '.mp3';

        $result = new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: strlen($audioContent),
        );

        $success = $result->saveToFile($tempFile);

        self::assertTrue($success);
        self::assertFileExists($tempFile);
        self::assertEquals($audioContent, file_get_contents($tempFile));

        // Cleanup
        @unlink($tempFile);
    }

    #[Test]
    public function saveToFileReturnsFalseOnFailure(): void
    {
        $result = new SpeechSynthesisResult(
            audioContent: 'audio',
            format: 'mp3',
            model: 'tts-1',
            voice: 'alloy',
            characterCount: 5,
        );

        // Try to save to a non-existent directory
        $success = $result->saveToFile('/non/existent/path/file.mp3');

        self::assertFalse($success);
    }
}
