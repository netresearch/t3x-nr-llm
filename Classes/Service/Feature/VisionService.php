<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;

/**
 * High-level service for image analysis and vision tasks.
 *
 * Provides specialized image analysis with accessibility,
 * SEO, and descriptive prompts.
 */
class VisionService
{
    private const PROMPT_ALT_TEXT = 'Generate a concise alt text for this image, under 125 characters, focused on essential information for screen readers. Be descriptive but brief.';
    private const PROMPT_SEO_TITLE = 'Generate an SEO-optimized title for this image, under 60 characters, that is compelling and keyword-rich for search rankings.';
    private const PROMPT_DESCRIPTION = 'Provide a comprehensive description of this image including subjects, setting, colors, mood, composition, and notable details.';

    public function __construct(
        private readonly LlmServiceManagerInterface $llmManager,
    ) {}

    /**
     * Generate accessibility-focused alt text for image.
     *
     * Optimized for screen readers and WCAG 2.1 Level AA compliance.
     * Output is concise (under 125 characters) and focuses on essential information.
     *
     * @param string|array<int, string> $imageUrl Single URL or array of URLs
     *
     * @return string|array<int, string> Alt text(s)
     */
    public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $options ??= new VisionOptions();
        if ($options->getMaxTokens() === null) {
            $options = $options->withMaxTokens(100);
        }
        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.5);
        }

        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, self::PROMPT_ALT_TEXT, $options);
        }

        return $this->processImage($imageUrl, self::PROMPT_ALT_TEXT, $options);
    }

    /**
     * Generate SEO-optimized title for image.
     *
     * Creates compelling, keyword-rich titles under 60 characters
     * for improved search rankings.
     *
     * @param string|array<int, string> $imageUrl Single URL or array of URLs
     *
     * @return string|array<int, string> Title(s)
     */
    public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $options ??= new VisionOptions();
        if ($options->getMaxTokens() === null) {
            $options = $options->withMaxTokens(50);
        }
        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.7);
        }

        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, self::PROMPT_SEO_TITLE, $options);
        }

        return $this->processImage($imageUrl, self::PROMPT_SEO_TITLE, $options);
    }

    /**
     * Generate detailed description of image.
     *
     * Provides comprehensive analysis including subjects, setting,
     * colors, mood, composition, and notable details.
     *
     * @param string|array<int, string> $imageUrl Single URL or array of URLs
     *
     * @return string|array<int, string> Description(s)
     */
    public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $options ??= new VisionOptions();
        if ($options->getMaxTokens() === null) {
            $options = $options->withMaxTokens(500);
        }
        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.7);
        }

        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, self::PROMPT_DESCRIPTION, $options);
        }

        return $this->processImage($imageUrl, self::PROMPT_DESCRIPTION, $options);
    }

    /**
     * Analyze image with custom prompt.
     *
     * Allows arbitrary image analysis queries with user-defined prompts.
     *
     * @param string|array<int, string> $imageUrl     Single URL or array of URLs
     * @param string                    $customPrompt Custom analysis prompt
     *
     * @return string|array<int, string> Analysis result(s)
     */
    public function analyzeImage(
        string|array $imageUrl,
        string $customPrompt,
        ?VisionOptions $options = null,
    ): string|array {
        $options ??= new VisionOptions();

        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, $customPrompt, $options);
        }

        return $this->processImage($imageUrl, $customPrompt, $options);
    }

    /**
     * Analyze image with full response object.
     *
     * Returns complete VisionResponse with metadata and usage statistics.
     *
     * @param string $imageUrl Image URL
     * @param string $prompt   Analysis prompt
     */
    public function analyzeImageFull(
        string $imageUrl,
        string $prompt,
        ?VisionOptions $options = null,
    ): VisionResponse {
        $options ??= new VisionOptions();
        $optionsArray = $options->toArray();
        $this->validateImageUrl($imageUrl);

        $content = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl,
                    'detail' => $optionsArray['detail_level'] ?? 'auto',
                ],
            ],
        ];

        // Create new options without detail_level
        $visionOptions = new VisionOptions(
            maxTokens: $options->getMaxTokens(),
            temperature: $options->getTemperature(),
            provider: $options->getProvider(),
            model: $options->getModel(),
        );

        return $this->llmManager->vision($content, $visionOptions);
    }

    /**
     * Process single image with prompt.
     */
    private function processImage(
        string $imageUrl,
        string $prompt,
        VisionOptions $options,
    ): string {
        $response = $this->analyzeImageFull($imageUrl, $prompt, $options);
        return $response->description;
    }

    /**
     * Process batch of images with prompt.
     *
     * @param array<int, string> $imageUrls
     *
     * @return array<int, string>
     */
    private function processBatch(
        array $imageUrls,
        string $prompt,
        VisionOptions $options,
    ): array {
        $results = [];

        foreach ($imageUrls as $imageUrl) {
            $results[] = $this->processImage($imageUrl, $prompt, $options);
        }

        return $results;
    }

    /**
     * Validate image URL.
     *
     * @throws InvalidArgumentException
     */
    private function validateImageUrl(string $imageUrl): void
    {
        // Check if it's a valid URL or base64 data URI
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            // Check for base64 data URI
            if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/', $imageUrl)) {
                throw new InvalidArgumentException(
                    'Invalid image URL or base64 data URI',
                    1919008030,
                );
            }
        }
    }
}
