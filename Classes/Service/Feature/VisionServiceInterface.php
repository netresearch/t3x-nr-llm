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
 * Consumers (controllers, alt-text wizards, tests, downstream
 * extensions) should depend on this interface rather than the concrete
 * `VisionService` so the implementation can be substituted without
 * inheritance.
 *
 * Most methods accept either a single image URL/data-URI or an array
 * of them; pass an array to batch in a single call. The full-response
 * variant `analyzeImageFull` always operates on a single image.
 */
interface VisionServiceInterface
{
    /**
     * Generate accessibility-focused alt text (≤ 125 chars, screen-reader friendly).
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array;

    /**
     * Generate SEO-optimised title (≤ 60 chars, keyword-rich).
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
