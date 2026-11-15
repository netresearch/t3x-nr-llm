<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use GdImage;
use JsonException;

/**
 * The five media types an A2A file may have, and the check each one passes
 * before a file counts as that type (spec 051 A2A-FILE1, ADR-284).
 *
 * A declared type is the remote's claim; the verdict here is what makes it a
 * fact. Every other type, archives, HTML and SVG among them, is refused.
 *
 * - `text/plain`: UTF-8, no control character but tab, line feed, form feed
 *   and carriage return, and no leading markup that declares HTML, SVG or XML.
 * - `application/json`: UTF-8 that parses as JSON within a depth of 64.
 * - `application/pdf`: starts with a `%PDF-1.` or `%PDF-2.` header, ends with
 *   `startxref` and `%%EOF`, is not encrypted, and names no active content
 *   (JavaScript, launch actions, embedded files, rich media, XFA forms),
 *   name escapes (`#4A`) resolved. A name inside a compressed object stream
 *   is not seen; nr-llm never renders or runs a PDF.
 * - `image/png`, `image/jpeg`: the signature, a size of at most
 *   {@see self::MAX_PIXELS} read from the header before anything is decoded,
 *   and a full decode by GD. Without GD neither type is accepted.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileValidator
{
    /** The accepted media types; ADR-284 names exactly these. */
    public const MEDIA_TYPES = ['text/plain', 'application/json', 'application/pdf', 'image/png', 'image/jpeg'];

    /** The largest image decoded: 4096 x 4096 pixels. */
    public const MAX_PIXELS = 16777216;

    private const JSON_DEPTH = 64;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /** Names that make a PDF do something when it is opened (ISO 32000). */
    private const PDF_ACTIVE_NAMES = '#/(?:JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|Encrypt)(?![A-Za-z0-9])#';

    /**
     * The media type without parameters, in lower case.
     */
    public static function normalise(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType, 2)[0]));
    }

    public static function accepts(string $mediaType): bool
    {
        return in_array(self::normalise($mediaType), self::MEDIA_TYPES, true);
    }

    /**
     * Why $bytes are not a file of $mediaType, or null when they are.
     */
    /**
     * The pixels an image's header names, read without decoding it; 0 for
     * bytes that name none.
     */
    public function pixels(string $bytes): int
    {
        $size = $this->quietly(static fn (): array|false => getimagesizefromstring($bytes));

        return is_array($size) && is_int($size[0] ?? null) && is_int($size[1] ?? null) ? $size[0] * $size[1] : 0;
    }

    public function refusal(string $mediaType, string $bytes): ?string
    {
        return match (self::normalise($mediaType)) {
            'text/plain'       => $this->textRefusal($bytes),
            'application/json' => $this->jsonRefusal($bytes),
            'application/pdf'  => $this->pdfRefusal($bytes),
            'image/png'        => $this->imageRefusal($bytes, self::PNG_SIGNATURE, IMAGETYPE_PNG),
            'image/jpeg'       => $this->imageRefusal($bytes, self::JPEG_SIGNATURE, IMAGETYPE_JPEG),
            default            => 'the media type is not accepted',
        };
    }

    private function textRefusal(string $bytes): ?string
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            return 'the text is not UTF-8';
        }

        if (preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $bytes) === 1) {
            return 'the text contains control characters';
        }

        $start = strtolower(ltrim(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes));
        foreach (['<!doctype', '<html', '<svg', '<?xml', '<script'] as $markup) {
            if (str_starts_with($start, $markup)) {
                return 'the text is markup, not plain text';
            }
        }

        return null;
    }

    private function jsonRefusal(string $bytes): ?string
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            return 'the JSON is not UTF-8';
        }

        try {
            json_decode($bytes, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'the content does not parse as JSON';
        }

        return null;
    }

    private function pdfRefusal(string $bytes): ?string
    {
        if (preg_match('/^%PDF-[12]\.\d/', $bytes) !== 1) {
            return 'the content has no PDF header';
        }

        $tail = substr($bytes, -2048);
        if (!str_contains($tail, 'startxref') || !str_contains(substr($bytes, -1024), '%%EOF')) {
            return 'the PDF has no cross-reference trailer';
        }

        $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $match): string => chr((int) hexdec($match[1])), $bytes) ?? $bytes;
        if (preg_match(self::PDF_ACTIVE_NAMES, $names) === 1) {
            return 'the PDF is encrypted or carries active content';
        }

        return null;
    }

    private function imageRefusal(string $bytes, string $signature, int $type): ?string
    {
        if (!str_starts_with($bytes, $signature)) {
            return 'the content does not start with the signature of its type';
        }

        $size = $this->quietly(static fn (): array|false => getimagesizefromstring($bytes));
        if (!is_array($size) || $size[2] !== $type || $size[0] < 1 || $size[1] < 1) {
            return 'the image header cannot be read';
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return 'the image is larger than this installation decodes';
        }

        if (!function_exists('imagecreatefromstring')) {
            return 'images cannot be decoded on this installation';
        }

        // The image is freed when it goes out of scope (imagedestroy() is a
        // no-op since PHP 8.0 and deprecated in 8.5).
        return $this->quietly(static fn (): GdImage|false => imagecreatefromstring($bytes)) instanceof GdImage ? null : 'the image does not decode';
    }

    /**
     * GD reports unreadable data as a warning as well as by its return value;
     * the return value is the answer here.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function quietly(callable $read): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }
}
