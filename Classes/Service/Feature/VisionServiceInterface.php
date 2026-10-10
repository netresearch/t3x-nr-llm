<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\VisionOptions;

/**
 * Public surface of the high-level image-analysis service.
 *
 * Consumers should depend on this interface so the implementation can
 * be substituted without inheritance. Most methods accept a single image
 * URL/data URI or an array. Arrays are processed sequentially, issuing one
 * analysis request per image. analyzeImageFull operates on one image.
 *
 * @api
 */
interface VisionServiceInterface
{
    /**
     * Request accessibility-focused alt text using a fixed prompt.
     *
     * The prompt requests fewer than 125 characters; the returned model
     * content is not length-checked or validated for accessibility compliance.
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array;

    /**
     * Request a concise image title using a fixed keyword-focused prompt.
     *
     * The prompt requests fewer than 60 characters; the returned model
     * content is not length-checked or validated for search-ranking effects.
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array;

    /**
     * Generate a comprehensive description (subjects, setting, colors, mood, composition).
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array;

    /**
     * Analyse one or more images with an arbitrary user-supplied prompt.
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function analyzeImage(
        string|array $imageUrl,
        string $customPrompt,
        ?VisionOptions $options = null,
    ): string|array;

    /**
     * Analyse a single image and return the full `VisionResponse` (description + usage metadata).
     *
     * @throws InvalidArgumentException when `$imageUrl` is neither a valid URL nor a `data:image/...` URI
     */
    public function analyzeImageFull(
        string $imageUrl,
        string $prompt,
        ?VisionOptions $options = null,
    ): VisionResponse;
}
