<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

/**
 * One file part of an accepted message, as checked at the request (spec 051
 * A2A-FILE1 to A2A-FILE4).
 *
 * A raw part carries its checked bytes; a url part only its URL, which the
 * worker fetches. The file id names the stored row and the reference the
 * task's history keeps in place of the bytes.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aInboundFile
{
    public const ORIGIN_RAW = 'raw';

    public const ORIGIN_URL = 'url';

    private function __construct(
        public string $fileId,
        public int $partIndex,
        public string $origin,
        public string $mediaType,
        public string $filename,
        public string $bytes,
        public string $url,
    ) {}

    public static function raw(string $fileId, int $partIndex, string $mediaType, string $filename, string $bytes): self
    {
        return new self($fileId, $partIndex, self::ORIGIN_RAW, $mediaType, $filename, $bytes, '');
    }

    public static function url(string $fileId, int $partIndex, string $mediaType, string $filename, string $url): self
    {
        return new self($fileId, $partIndex, self::ORIGIN_URL, $mediaType, $filename, '', $url);
    }

    public function isRaw(): bool
    {
        return $this->origin === self::ORIGIN_RAW;
    }

    /**
     * The SHA-256 of a raw part's bytes; empty for a url part until the
     * worker fetched it.
     */
    public function sha256(): string
    {
        return $this->isRaw() ? hash('sha256', $this->bytes) : '';
    }
}
