<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UnsupportedFormatException::class)]
class UnsupportedFormatExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function forFormatCreatesExceptionWithoutSupportedList(): void
    {
        $exception = UnsupportedFormatException::forFormat('xyz', 'image');

        self::assertStringContainsString('Format "xyz" is not supported', $exception->getMessage());
        self::assertStringNotContainsString('Supported:', $exception->getMessage());
        self::assertEquals('image', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('xyz', $exception->context['format']);
        self::assertNull($exception->context['supported']);
    }

    #[Test]
    public function forFormatIncludesSupportedFormats(): void
    {
        $supported = ['mp3', 'wav', 'ogg'];
        $exception = UnsupportedFormatException::forFormat('aiff', 'audio', $supported);

        self::assertStringContainsString('Supported: mp3, wav, ogg', $exception->getMessage());
        self::assertNotNull($exception->context);
        self::assertEquals($supported, $exception->context['supported']);
    }

    #[Test]
    public function audioFormatCreatesCorrectException(): void
    {
        $exception = UnsupportedFormatException::audioFormat('aiff');

        self::assertStringContainsString('Audio format "aiff" is not supported', $exception->getMessage());
        self::assertStringContainsString('flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav, webm', $exception->getMessage());
        self::assertEquals('speech', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('aiff', $exception->context['format']);
        self::assertEquals('audio', $exception->context['type']);
    }

    #[Test]
    public function imageFormatCreatesCorrectException(): void
    {
        $exception = UnsupportedFormatException::imageFormat('bmp');

        self::assertStringContainsString('Image format "bmp" is not supported', $exception->getMessage());
        self::assertStringContainsString('png, jpg, gif, webp', $exception->getMessage());
        self::assertEquals('image', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('bmp', $exception->context['format']);
        self::assertEquals('image', $exception->context['type']);
    }
}
