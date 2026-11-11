<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * One `Part`: exactly one of text, raw bytes, a URL or a JSON value, plus the
 * optional `metadata`, `filename` and `mediaType` (proto `Part`).
 *
 * `raw` holds the decoded bytes; the JSON form is base64 (A2A-P3). A part of a
 * kind the proto does not name never becomes an instance of this class: the
 * parser skips it (A2A-P4).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPart
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public A2aPartKind $kind,
        public string $text,
        public string $raw,
        public string $url,
        public mixed $data,
        public array $metadata,
        public string $filename,
        public string $mediaType,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function text(string $text, string $mediaType = '', array $metadata = [], string $filename = ''): self
    {
        return new self(A2aPartKind::TEXT, $text, '', '', null, $metadata, $filename, $mediaType);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function raw(string $bytes, string $mediaType = '', string $filename = '', array $metadata = []): self
    {
        return new self(A2aPartKind::RAW, '', $bytes, '', null, $metadata, $filename, $mediaType);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function url(string $url, string $mediaType = '', string $filename = '', array $metadata = []): self
    {
        return new self(A2aPartKind::URL, '', '', $url, null, $metadata, $filename, $mediaType);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function data(mixed $data, string $mediaType = '', array $metadata = [], string $filename = ''): self
    {
        return new self(A2aPartKind::DATA, '', '', '', $data, $metadata, $filename, $mediaType);
    }

    /**
     * The ProtoJSON form. Empty optional strings and an empty metadata object
     * are left out, as a proto3 serialiser leaves out default values.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = match ($this->kind) {
            A2aPartKind::TEXT => ['text' => $this->text],
            A2aPartKind::RAW  => ['raw' => base64_encode($this->raw)],
            A2aPartKind::URL  => ['url' => $this->url],
            A2aPartKind::DATA => ['data' => $this->data],
        };

        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }

        if ($this->filename !== '') {
            $json['filename'] = $this->filename;
        }

        if ($this->mediaType !== '') {
            $json['mediaType'] = $this->mediaType;
        }

        return $json;
    }
}
