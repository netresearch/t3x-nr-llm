<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\AbstractOptions;

/**
 * Options for image generation (DALL-E).
 */
final class ImageGenerationOptions extends AbstractOptions
{
    private const array VALID_MODELS = ['dall-e-2', 'dall-e-3'];
    private const array VALID_QUALITIES = ['standard', 'hd'];
    private const array VALID_STYLES = ['vivid', 'natural'];
    private const array VALID_FORMATS = ['url', 'b64_json'];
    private const array VALID_SIZES_DALLE3 = ['1024x1024', '1792x1024', '1024x1792'];
    private const array VALID_SIZES_DALLE2 = ['256x256', '512x512', '1024x1024'];

    public function __construct(
        public readonly ?string $model = 'dall-e-3',
        public readonly ?string $size = '1024x1024',
        public readonly ?string $quality = 'standard',
        public readonly ?string $style = 'vivid',
        public readonly ?string $format = 'url',
    ) {
        if ($this->model !== null) {
            self::validateEnum($this->model, self::VALID_MODELS, 'model');
        }
        if ($this->quality !== null) {
            self::validateEnum($this->quality, self::VALID_QUALITIES, 'quality');
        }
        if ($this->style !== null) {
            self::validateEnum($this->style, self::VALID_STYLES, 'style');
        }
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        $this->validateSize();
    }

    private function validateSize(): void
    {
        if ($this->size === null) {
            return;
        }

        $validSizes = $this->model === 'dall-e-2'
            ? self::VALID_SIZES_DALLE2
            : self::VALID_SIZES_DALLE3;

        if (!in_array($this->size, $validSizes, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid size "%s" for model %s. Valid sizes: %s',
                    $this->size,
                    $this->model,
                    implode(', ', $validSizes),
                ),
                9662872869,
            );
        }
    }

    public function toArray(): array
    {
        return $this->filterNull([
            'model' => $this->model,
            'size' => $this->size,
            'quality' => $this->quality,
            'style' => $this->style,
            'response_format' => $this->format,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): static
    {
        $model = $options['model'] ?? null;
        $size = $options['size'] ?? null;
        $quality = $options['quality'] ?? null;
        $style = $options['style'] ?? null;
        $format = $options['format'] ?? $options['response_format'] ?? null;

        return new self(
            model: is_string($model) ? $model : null,
            size: is_string($size) ? $size : null,
            quality: is_string($quality) ? $quality : null,
            style: is_string($style) ? $style : null,
            format: is_string($format) ? $format : null,
        );
    }

    /**
     * Create options for high-definition output.
     */
    public static function hd(string $size = '1024x1024'): self
    {
        return new self(quality: 'hd', size: $size);
    }

    /**
     * Create options for wide landscape format.
     */
    public static function landscape(): self
    {
        return new self(size: '1792x1024');
    }

    /**
     * Create options for tall portrait format.
     */
    public static function portrait(): self
    {
        return new self(size: '1024x1792');
    }

    /**
     * Create options for natural (less dramatic) style.
     */
    public static function natural(): self
    {
        return new self(style: 'natural');
    }

    /**
     * Get valid sizes for a model.
     *
     * @return array<int, string>
     */
    public static function getValidSizes(string $model = 'dall-e-3'): array
    {
        return $model === 'dall-e-2' ? self::VALID_SIZES_DALLE2 : self::VALID_SIZES_DALLE3;
    }
}
