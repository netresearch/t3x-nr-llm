<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Value Object describing one item in a vision-request content list.
 *
 * Mirrors the discriminated-union shape every supported provider expects:
 * a vision call's `content` is a list whose items are either a text
 * fragment or an image reference (URL or `data:` URI). Two factories
 * cover those two kinds; `fromArray()` accepts the wire shape verbatim;
 * `toArray()` emits it back.
 *
 * Replaces the
 * `array<int, array{type: string, image_url?: array{url: string}, text?: string}>`
 * shape currently used by
 * :php:`LlmServiceManager::vision()` /
 * :php:`VisionCapableInterface::analyzeImage()`. The provider migration
 * lands in a follow-up slice; this VO is purely additive so callers
 * can opt in early.
 */
final readonly class VisionContent implements JsonSerializable
{
    public const TYPE_TEXT      = 'text';
    public const TYPE_IMAGE_URL = 'image_url';

    public const DETAIL_LOW  = 'low';
    public const DETAIL_HIGH = 'high';
    public const DETAIL_AUTO = 'auto';

    /**
     * @var list<string> recognised discriminator values, mirroring the
     *                   wire format of every supported provider
     */
    public const KNOWN_TYPES = [self::TYPE_TEXT, self::TYPE_IMAGE_URL];

    /**
     * @var list<string> recognised values for the OpenAI `image_url.detail`
     *                   knob; provider-specific use only — Claude / Gemini
     *                   ignore it
     */
    public const KNOWN_DETAILS = [self::DETAIL_LOW, self::DETAIL_HIGH, self::DETAIL_AUTO];

    /**
     * @param string      $type     one of the `TYPE_*` constants
     * @param string|null $text     text payload when `$type === TYPE_TEXT`,
     *                              `null` otherwise
     * @param string|null $imageUrl URL or `data:` URI when
     *                              `$type === TYPE_IMAGE_URL`, `null`
     *                              otherwise
     * @param string|null $detail   OpenAI image-detail level (`low` /
     *                              `high` / `auto`); only meaningful with
     *                              `TYPE_IMAGE_URL`. Other providers
     *                              ignore it but the field is preserved
     *                              through `toArray()` for round-trip
     *                              fidelity.
     */
    public function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $imageUrl = null,
        public ?string $detail = null,
    ) {
        if (!\in_array($this->type, self::KNOWN_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid VisionContent type "%s". Valid types: %s',
                    $this->type,
                    implode(', ', self::KNOWN_TYPES),
                ),
                1745420001,
            );
        }
        if ($this->type === self::TYPE_TEXT && ($this->text === null || $this->text === '')) {
            throw new InvalidArgumentException(
                'VisionContent of type "text" requires a non-empty text payload.',
                1745420002,
            );
        }
        if ($this->type === self::TYPE_IMAGE_URL && ($this->imageUrl === null || $this->imageUrl === '')) {
            throw new InvalidArgumentException(
                'VisionContent of type "image_url" requires a non-empty URL.',
                1745420003,
            );
        }
        if ($this->detail !== null && !\in_array($this->detail, self::KNOWN_DETAILS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid VisionContent detail "%s". Valid values: %s',
                    $this->detail,
                    implode(', ', self::KNOWN_DETAILS),
                ),
                1745420004,
            );
        }
        if ($this->detail !== null && $this->type !== self::TYPE_IMAGE_URL) {
            throw new InvalidArgumentException(
                'VisionContent detail can only be set on image_url items.',
                1745420005,
            );
        }
    }

    /**
     * Create a text fragment.
     */
    public static function text(string $text): self
    {
        return new self(type: self::TYPE_TEXT, text: $text);
    }

    /**
     * Create an image reference.
     *
     * `$url` may be a remote URL or a `data:image/...;base64,...` URI;
     * the VO does not validate the URL form because every provider
     * accepts both transparently.
     *
     * `$detail` is OpenAI-specific (`low` / `high` / `auto`); other
     * providers silently ignore it. Use one of the `DETAIL_*` constants.
     */
    public static function imageUrl(string $url, ?string $detail = null): self
    {
        return new self(type: self::TYPE_IMAGE_URL, imageUrl: $url, detail: $detail);
    }

    /**
     * Reconstruct from the provider wire shape.
     *
     * @param array{type?: string, text?: string, image_url?: array{url?: string}|string} $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? '';
        if (!\is_string($type)) {
            $type = '';
        }

        if ($type === self::TYPE_TEXT) {
            $text = $data['text'] ?? '';

            return new self(
                type: self::TYPE_TEXT,
                text: \is_string($text) ? $text : '',
            );
        }

        if ($type === self::TYPE_IMAGE_URL) {
            $imageUrl = $data['image_url'] ?? null;
            $detail   = is_array($imageUrl) && isset($imageUrl['detail']) && is_string($imageUrl['detail'])
                ? $imageUrl['detail']
                : null;

            return new self(
                type: self::TYPE_IMAGE_URL,
                imageUrl: self::extractImageUrl($imageUrl),
                detail: $detail,
            );
        }

        // Unknown / missing type: let the constructor's invariant guard
        // emit the canonical error so the rejection message matches the
        // direct-construction path.
        return new self(type: $type);
    }

    /**
     * Serialise to the wire shape every supported provider accepts.
     * Idempotent: `VisionContent::fromArray($vc->toArray()) == $vc`.
     *
     * @return array{type: string, text?: string, image_url?: array{url: string, detail?: string}}
     */
    public function toArray(): array
    {
        if ($this->type === self::TYPE_TEXT) {
            return [
                'type' => self::TYPE_TEXT,
                'text' => $this->text ?? '',
            ];
        }

        $imageUrl = ['url' => $this->imageUrl ?? ''];
        if ($this->detail !== null) {
            $imageUrl['detail'] = $this->detail;
        }

        return [
            'type'      => self::TYPE_IMAGE_URL,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @return array{type: string, text?: string, image_url?: array{url: string, detail?: string}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE_URL;
    }

    /**
     * Pull a URL string out of either the canonical
     * `{image_url: {url: '...'}}` shape or the legacy flat
     * `{image_url: '...'}` form some integrations still emit.
     */
    private static function extractImageUrl(mixed $raw): string
    {
        if (\is_string($raw)) {
            return $raw;
        }
        if (\is_array($raw)) {
            $url = $raw['url'] ?? '';

            return \is_string($url) ? $url : '';
        }

        return '';
    }
}
