<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\File;

use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The five media types and their checks (spec 051 A2A-FILE1, ADR-284).
 */
#[CoversClass(A2aFileValidator::class)]
final class A2aFileValidatorTest extends TestCase
{
    /**
     * ADR-284 names exactly these five; widening or narrowing the list needs
     * an amendment of the ADR first.
     */
    #[Test]
    public function theAcceptedTypesAreTheFiveOfTheAdr(): void
    {
        self::assertSame(['text/plain', 'application/json', 'application/pdf', 'image/png', 'image/jpeg'], A2aFileValidator::MEDIA_TYPES);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validFiles(): iterable
    {
        yield 'text' => ['text/plain', "Line one\r\nLine two\twith a tab.\n"];
        yield 'text with a parameter' => ['Text/Plain; charset=utf-8', 'Grüße'];
        yield 'text with a BOM' => ['text/plain', "\xEF\xBB\xBFHello"];
        yield 'json' => ['application/json', '{"a": [1, 2, {"b": null}]}'];
        yield 'pdf' => ['application/pdf', self::pdf()];
        yield 'png' => ['image/png', self::png()];
        yield 'jpeg' => ['image/jpeg', self::jpeg()];
    }

    #[Test]
    #[DataProvider('validFiles')]
    public function aFileThatIsItsTypePasses(string $mediaType, string $bytes): void
    {
        self::assertNull((new A2aFileValidator())->refusal($mediaType, $bytes));
        self::assertTrue(A2aFileValidator::accepts($mediaType));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'text not UTF-8' => ['text/plain', "caf\xE9", 'not UTF-8'];
        yield 'text with NUL' => ['text/plain', "a\x00b", 'control characters'];
        yield 'text with an escape sequence' => ['text/plain', "\x1B[31mred", 'control characters'];
        yield 'HTML as text' => ['text/plain', '  <!DOCTYPE html><html></html>', 'markup'];
        yield 'HTML element as text' => ['text/plain', '<html><body>x</body></html>', 'markup'];
        yield 'SVG as text' => ['text/plain', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'markup'];
        yield 'XML as text' => ['text/plain', "\xEF\xBB\xBF<?xml version=\"1.0\"?><svg/>", 'markup'];
        yield 'script as text' => ['text/plain', '<script>alert(1)</script>', 'markup'];
        yield 'zip as text' => ['text/plain', "PK\x03\x04\x14\x00\x00\x00", 'control characters'];
        yield 'json that does not parse' => ['application/json', '{"a": ', 'does not parse'];
        yield 'json not UTF-8' => ['application/json', "\"caf\xE9\"", 'not UTF-8'];
        yield 'json too deep' => ['application/json', str_repeat('[', 65) . str_repeat(']', 65), 'does not parse'];
        yield 'pdf without header' => ['application/pdf', 'junk' . self::pdf(), 'no PDF header'];
        yield 'pdf without trailer' => ['application/pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n", 'trailer'];
        yield 'pdf without EOF' => ['application/pdf', "%PDF-1.4\n1 0 obj<<>>endobj\nstartxref\n0\n", 'trailer'];
        yield 'pdf with JavaScript' => ['application/pdf', self::pdf('/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'), 'active content'];
        yield 'pdf with an escaped JavaScript name' => ['application/pdf', self::pdf('/OpenAction << /S /J#61vaScript >>'), 'active content'];
        yield 'pdf with a launch action' => ['application/pdf', self::pdf('/AA << /O << /S /Launch /F (cmd.exe) >> >>'), 'active content'];
        yield 'pdf with an embedded file' => ['application/pdf', self::pdf('/Names << /EmbeddedFiles 5 0 R >>'), 'active content'];
        yield 'pdf encrypted' => ['application/pdf', self::pdf('/Encrypt 9 0 R'), 'active content'];
        yield 'png declared as jpeg' => ['image/jpeg', self::png(), 'signature'];
        yield 'jpeg declared as png' => ['image/png', self::jpeg(), 'signature'];
        yield 'png signature only' => ['image/png', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 40), 'header'];
        yield 'png that does not decode' => ['image/png', self::truncatedPng(), 'does not decode'];
        yield 'png too large' => ['image/png', self::pngHeader(5000, 5000), 'larger'];
        yield 'html' => ['text/html', '<p>x</p>', 'not accepted'];
        yield 'svg' => ['image/svg+xml', '<svg/>', 'not accepted'];
        yield 'zip' => ['application/zip', "PK\x03\x04", 'not accepted'];
        yield 'no type' => ['', 'text', 'not accepted'];
    }

    #[Test]
    #[DataProvider('invalidFiles')]
    public function aFileThatIsNotItsTypeIsRefused(string $mediaType, string $bytes, string $reason): void
    {
        $refusal = (new A2aFileValidator())->refusal($mediaType, $bytes);

        self::assertNotNull($refusal);
        self::assertStringContainsString($reason, $refusal);
    }

    /**
     * Only bytes with a PNG or JPEG signature are read for their size:
     * getimagesize() inflates a compressed SWF to find its size, which a
     * small upload can make cost hundreds of MiB.
     */
    #[Test]
    public function onlyPngAndJpegBytesAreReadForTheirPixels(): void
    {
        $validator = new A2aFileValidator();

        self::assertSame(0, $validator->pixels(self::compressedSwf(5000, 5000)));
        self::assertSame(12, $validator->pixels(self::png()));
        self::assertSame(12, $validator->pixels(self::jpeg()));
    }

    #[Test]
    public function theTypeIsReadWithoutParametersAndCase(): void
    {
        self::assertSame('application/json', A2aFileValidator::normalise(' Application/JSON ; charset=utf-8'));
        self::assertFalse(A2aFileValidator::accepts('application/zip'));
    }

    public static function pdf(string $catalogue = ''): string
    {
        return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R " . $catalogue . " >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\nxref\n0 3\n0000000000 65535 f \ntrailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n9\n%%EOF\n";
    }

    public static function png(): string
    {
        $image = imagecreatetruecolor(4, 3);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 3);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * A zlib-compressed SWF (`CWS`) whose frame names the given size;
     * getimagesize() reads that size only after inflating the body.
     */
    public static function compressedSwf(int $width, int $height): string
    {
        // The frame is a RECT in twips (1/20 pixel): 5 bits for the field
        // width, then x min, x max, y min, y max in 20 bits each.
        $bits = sprintf('%05b', 20);
        foreach ([0, $width * 20, 0, $height * 20] as $value) {
            $bits .= sprintf('%020b', $value);
        }

        $frame = '';
        foreach (str_split(str_pad($bits, 88, '0'), 8) as $byte) {
            $frame .= chr((int) bindec($byte));
        }

        $body = $frame . str_repeat("\x00", 60);

        return 'CWS' . chr(10) . pack('V', 8 + strlen($body)) . (string) gzcompress($body, 0);
    }

    /**
     * A PNG whose header says 4 x 3 pixels and whose image data is cut off.
     */
    private static function truncatedPng(): string
    {
        return substr(self::png(), 0, 40);
    }

    /**
     * The signature and an IHDR chunk naming the given size, nothing more.
     */
    private static function pngHeader(int $width, int $height): string
    {
        $data = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $data . pack('N', crc32('IHDR' . $data));
    }
}
