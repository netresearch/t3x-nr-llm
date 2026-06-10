<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\AbstractOptions;

/**
 * Options for image generation (DALL-E).
 */
final class ImageGenerationOptions extends AbstractOptions
{
    private const VALID_MODELS = ['dall-e-2', 'dall-e-3'];
    private const VALID_QUALITIES = ['standard', 'hd'];
    private const VALID_STYLES = ['vivid', 'natural'];
    private const VALID_FORMATS = ['url', 'b64_json'];
    private const VALID_SIZES_DALLE3 = ['1024x1024', '1792x1024', '1024x1792'];
    private const VALID_SIZES_DALLE2 = ['256x256', '512x512', '1024x1024'];
    // OpenAI's gpt-image-* family (gpt-image-1, -mini, -1.5, -2, …) replaced DALL·E. It exposes
    // a different square/landscape/portrait size set and always returns b64_json; `response_format`
    // and `style` are not accepted (the service omits them for non-DALL·E models).
    private const VALID_SIZES_GPT_IMAGE = ['1024x1024', '1536x1024', '1024x1536', 'auto'];
    private const GPT_IMAGE_PREFIX = 'gpt-image-';

    // Arbitrary WxH sizes for gpt-image-* (per OpenAI docs, June 2026): gpt-image-2
    // accepts any WIDTHxHEIGHT where both dimensions are divisible by 16, the aspect
    // ratio lies between 1:3 and 3:1 (inclusive), and the size does not exceed
    // 3840x2160. The standard sizes above remain valid (they satisfy these rules);
    // 'auto' lets the model pick. Other extensions rely on this contract.
    private const GPT_IMAGE_DIMENSION_STEP = 16;
    private const GPT_IMAGE_MAX_WIDTH = 3840;
    private const GPT_IMAGE_MAX_HEIGHT = 2160;
    private const GPT_IMAGE_MAX_ASPECT = 3;

    public function __construct(
        public readonly ?string $model = 'dall-e-3',
        public readonly ?string $size = '1024x1024',
        public readonly ?string $quality = 'standard',
        public readonly ?string $style = 'vivid',
        public readonly ?string $format = 'url',
    ) {
        if ($this->model !== null) {
            $this->validateModel($this->model);
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

    /**
     * Accept the two DALL·E models plus any member of the gpt-image-* family (resolved by
     * prefix so new point releases like gpt-image-2-2026-04-21 need no code change).
     */
    private function validateModel(string $model): void
    {
        if (in_array($model, self::VALID_MODELS, true) || str_starts_with($model, self::GPT_IMAGE_PREFIX)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'model must be one of: %s, or a gpt-image-* model, got "%s"',
                implode(', ', self::VALID_MODELS),
                $model,
            ),
            8287317141,
        );
    }

    private function validateSize(): void
    {
        if ($this->size === null) {
            return;
        }

        if ($this->model !== null && str_starts_with($this->model, self::GPT_IMAGE_PREFIX)) {
            $this->validateGptImageSize($this->size);
            return;
        }

        $validSizes = $this->model === 'dall-e-2' ? self::VALID_SIZES_DALLE2 : self::VALID_SIZES_DALLE3;

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

    /**
     * Validate a gpt-image-* size: the documented standard sizes, 'auto',
     * or any arbitrary WIDTHxHEIGHT where both dimensions are divisible
     * by 16, the aspect ratio is between 1:3 and 3:1 (inclusive), and
     * the size does not exceed 3840x2160 (per OpenAI docs, June 2026).
     *
     * @throws InvalidArgumentException
     */
    private function validateGptImageSize(string $size): void
    {
        if (in_array($size, self::VALID_SIZES_GPT_IMAGE, true)) {
            return;
        }

        if (preg_match('/^(\d{1,5})x(\d{1,5})$/', $size, $matches) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid size "%s" for model %s. Expected WIDTHxHEIGHT (e.g. "1024x1024"), "auto", or one of: %s',
                    $size,
                    $this->model,
                    implode(', ', self::VALID_SIZES_GPT_IMAGE),
                ),
                9662872869,
            );
        }

        $width = (int)$matches[1];
        $height = (int)$matches[2];

        if (
            $width < self::GPT_IMAGE_DIMENSION_STEP
            || $height < self::GPT_IMAGE_DIMENSION_STEP
            || $width % self::GPT_IMAGE_DIMENSION_STEP !== 0
            || $height % self::GPT_IMAGE_DIMENSION_STEP !== 0
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid size "%s" for model %s: width and height must both be divisible by %d',
                    $size,
                    $this->model,
                    self::GPT_IMAGE_DIMENSION_STEP,
                ),
                4810231766,
            );
        }

        if ($width > self::GPT_IMAGE_MAX_WIDTH || $height > self::GPT_IMAGE_MAX_HEIGHT) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid size "%s" for model %s: maximum supported size is %dx%d',
                    $size,
                    $this->model,
                    self::GPT_IMAGE_MAX_WIDTH,
                    self::GPT_IMAGE_MAX_HEIGHT,
                ),
                7269345118,
            );
        }

        // Aspect ratio between 1:3 and 3:1 inclusive — integer math avoids
        // float comparison artefacts: W/H <= 3 ⇔ W <= 3H, W/H >= 1/3 ⇔ 3W >= H.
        if ($width > self::GPT_IMAGE_MAX_ASPECT * $height || $height > self::GPT_IMAGE_MAX_ASPECT * $width) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid size "%s" for model %s: aspect ratio must be between 1:3 and 3:1',
                    $size,
                    $this->model,
                ),
                6203914577,
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
     * Get the standard (enumerable) sizes for a model. gpt-image-* models
     * additionally accept arbitrary WIDTHxHEIGHT strings — both dimensions
     * divisible by 16, aspect ratio between 1:3 and 3:1, max 3840x2160 —
     * which cannot be enumerated here; see `validateGptImageSize()`.
     *
     * @return array<int, string>
     */
    public static function getValidSizes(string $model = 'dall-e-3'): array
    {
        if (str_starts_with($model, self::GPT_IMAGE_PREFIX)) {
            return self::VALID_SIZES_GPT_IMAGE;
        }

        return $model === 'dall-e-2' ? self::VALID_SIZES_DALLE2 : self::VALID_SIZES_DALLE3;
    }
}
