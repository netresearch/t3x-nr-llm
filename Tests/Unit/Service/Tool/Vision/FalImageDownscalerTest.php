<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Vision;

use Netresearch\NrLlm\Service\Tool\Vision\FalImageDownscaler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * What leaves the installation is measured, whatever the processing reported.
 * The real scaling is in the functional test of this class.
 */
#[CoversClass(FalImageDownscaler::class)]
final class FalImageDownscalerTest extends TestCase
{
    #[Test]
    public function anImageWithinTheBoundBecomesADataUri(): void
    {
        $bytes = $this->png(10, 5);

        self::assertSame('data:image/png;base64,' . base64_encode($bytes), (new FalImageDownscaler())->encode($bytes, 10));
    }

    /**
     * Processing that fails hands back the original without an error, so a
     * 6000-pixel file must not go out as if it had been scaled.
     */
    #[Test]
    public function anImageOverTheBoundIsNotSent(): void
    {
        self::assertNull((new FalImageDownscaler())->encode($this->png(11, 5), 10));
        self::assertNull((new FalImageDownscaler())->encode($this->png(5, 11), 10));
    }

    #[Test]
    public function somethingThatIsNotAnImageIsNotSent(): void
    {
        self::assertNull((new FalImageDownscaler())->encode('', 10));
        self::assertNull((new FalImageDownscaler())->encode('<svg xmlns="http://www.w3.org/2000/svg"/>', 10));
    }

    #[Test]
    public function aFormatNoProviderTakesIsNotSent(): void
    {
        // A minimal BMP header: an image to getimagesize, not to a model.
        $bmp = 'BM' . pack('V', 54) . pack('V', 0) . pack('V', 54) . pack('V', 40) . pack('V', 2) . pack('V', 2) . pack('v', 1) . pack('v', 24) . str_repeat("\0", 24);
        self::assertNotFalse(getimagesizefromstring($bmp));

        self::assertNull((new FalImageDownscaler())->encode($bmp, 10));
    }

    #[Test]
    public function asksForTheBoundOnBothEdges(): void
    {
        $processed = self::createStub(ProcessedFile::class);
        $processed->method('getContents')->willReturn($this->png(8, 8));

        $file = $this->createMock(File::class);
        $file->expects(self::once())
            ->method('process')
            ->with(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['maxWidth' => 8, 'maxHeight' => 8])
            ->willReturn($processed);

        self::assertNotNull((new FalImageDownscaler())->dataUri($file, 8));
    }

    #[Test]
    public function anImageWithinTheBoundIsReadFromTheOriginal(): void
    {
        $processed = self::createStub(ProcessedFile::class);
        $processed->method('usesOriginalFile')->willReturn(true);
        $processed->method('getContents')->willThrowException(new RuntimeException('processing folder on another storage', 1798000013));

        $file = self::createStub(File::class);
        $file->method('process')->willReturn($processed);
        $file->method('getContents')->willReturn($this->png(8, 8));

        self::assertNotNull((new FalImageDownscaler())->dataUri($file, 8));
    }

    #[Test]
    public function aGifIsAskedForAsAPng(): void
    {
        $processed = self::createStub(ProcessedFile::class);
        $processed->method('getContents')->willReturn($this->png(8, 8));

        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('GIF');
        $file->expects(self::once())
            ->method('process')
            ->with(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['maxWidth' => 8, 'maxHeight' => 8, 'fileExtension' => 'png'])
            ->willReturn($processed);

        self::assertNotNull((new FalImageDownscaler())->dataUri($file, 8));
    }

    #[Test]
    public function aGifThatStayedOneIsNotSent(): void
    {
        self::assertNull((new FalImageDownscaler())->encode('GIF89a' . pack('v', 8) . pack('v', 8) . str_repeat("\0", 8), 10));
    }

    #[Test]
    public function aProcessorThatThrowsSendsNothing(): void
    {
        $file = self::createStub(File::class);
        $file->method('process')->willThrowException(new RuntimeException('no imagemagick', 1798000012));

        self::assertNull((new FalImageDownscaler())->dataUri($file, 8));
    }

    /**
     * A PNG header is all getimagesize and finfo read.
     */
    private function png(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', $width) . pack('N', $height)
            . "\x08\x02\x00\x00\x00" . pack('N', 0);
    }
}
