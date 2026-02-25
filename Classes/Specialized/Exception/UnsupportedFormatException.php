<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a file format is not supported.
 *
 * Used for audio formats (speech services) and image formats (generation).
 */
final class UnsupportedFormatException extends SpecializedServiceException
{
    /**
     * Create exception for an unsupported format.
     *
     * @param string                  $format           The unsupported format
     * @param string                  $service          The service identifier
     * @param array<int, string>|null $supportedFormats List of supported formats
     */
    public static function forFormat(
        string $format,
        string $service,
        ?array $supportedFormats = null,
    ): self {
        $message = sprintf('Format "%s" is not supported', $format);

        if ($supportedFormats !== null) {
            $message .= sprintf('. Supported: %s', implode(', ', $supportedFormats));
        }

        return new self(
            $message,
            $service,
            [
                'format' => $format,
                'supported' => $supportedFormats,
            ],
        );
    }

    /**
     * Create exception for unsupported audio format.
     *
     * @param string $format The unsupported audio format
     */
    public static function audioFormat(string $format): self
    {
        return new self(
            sprintf('Audio format "%s" is not supported. Supported: flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav, webm', $format),
            'speech',
            [
                'format' => $format,
                'type' => 'audio',
            ],
        );
    }

    /**
     * Create exception for unsupported image format.
     *
     * @param string $format The unsupported image format
     */
    public static function imageFormat(string $format): self
    {
        return new self(
            sprintf('Image format "%s" is not supported. Supported: png, jpg, gif, webp', $format),
            'image',
            [
                'format' => $format,
                'type' => 'image',
            ],
        );
    }
}
