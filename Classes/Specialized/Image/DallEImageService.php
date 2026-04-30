<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\MultipartBodyBuilderTrait;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Throwable;

/**
 * DALL-E image generation service.
 *
 * Provides AI image generation via OpenAI's DALL-E API.
 *
 * Features:
 * - DALL-E 2 and DALL-E 3 models
 * - Multiple sizes (256x256 to 1792x1024)
 * - HD quality option (DALL-E 3)
 * - Vivid and natural styles
 * - Image editing and variations (DALL-E 2)
 *
 * @see https://platform.openai.com/docs/guides/images
 */
final class DallEImageService extends AbstractSpecializedService
{
    use MultipartBodyBuilderTrait;

    private const API_URL = 'https://api.openai.com/v1/images';
    private const DEFAULT_MODEL = 'dall-e-3';
    private const DEFAULT_SIZE = '1024x1024';

    /** Model capabilities. */
    private const MODEL_CAPABILITIES = [
        'dall-e-2' => [
            'sizes' => ['256x256', '512x512', '1024x1024'],
            'max_prompt_length' => 1000,
            'supports_quality' => false,
            'supports_style' => false,
            'supports_editing' => true,
            'supports_variations' => true,
        ],
        'dall-e-3' => [
            'sizes' => ['1024x1024', '1792x1024', '1024x1792'],
            'max_prompt_length' => 4000,
            'supports_quality' => true,
            'supports_style' => true,
            'supports_editing' => false,
            'supports_variations' => false,
        ],
    ];

    /**
     * Generate an image from a text prompt.
     *
     * @param string                                      $prompt  Text description of desired image
     * @param ImageGenerationOptions|array<string, mixed> $options Generation options
     *
     * @throws ServiceUnavailableException
     *
     * @return ImageGenerationResult Generation result
     */
    public function generate(
        string $prompt,
        ImageGenerationOptions|array $options = [],
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $optionsArray = $options->toArray();
        $model = is_string($optionsArray['model'] ?? null) ? $optionsArray['model'] : self::DEFAULT_MODEL;
        $size = is_string($optionsArray['size'] ?? null) ? $optionsArray['size'] : self::DEFAULT_SIZE;
        $quality = is_string($optionsArray['quality'] ?? null) ? $optionsArray['quality'] : 'standard';
        $style = is_string($optionsArray['style'] ?? null) ? $optionsArray['style'] : 'vivid';

        $this->validatePrompt($prompt, $model);

        $payload = $this->buildGeneratePayload($prompt, $optionsArray);
        $response = $this->sendJsonRequest('generations', $payload);

        /** @var array<int, array{url?: string, b64_json?: string, revised_prompt?: string}> $responseData */
        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $data = $responseData[0] ?? [];

        $this->usageTracker->trackUsage('image', 'dall-e:' . $model, [
            'size' => $size,
            'quality' => $quality,
        ]);

        return new ImageGenerationResult(
            url: $data['url'] ?? '',
            base64: $data['b64_json'] ?? null,
            prompt: $prompt,
            revisedPrompt: $data['revised_prompt'] ?? null,
            model: $model,
            size: $size,
            provider: 'dall-e',
            metadata: [
                'quality' => $quality,
                'style' => $style,
            ],
        );
    }

    /**
     * Generate multiple images from a text prompt.
     *
     * Note: DALL-E 3 only supports n=1, multiple calls will be made.
     *
     * @param string                                      $prompt  Text description of desired image
     * @param int                                         $count   Number of images to generate (1-10 for DALL-E 2, any for DALL-E 3)
     * @param ImageGenerationOptions|array<string, mixed> $options Generation options
     *
     * @return array<int, ImageGenerationResult> Generation results
     */
    public function generateMultiple(
        string $prompt,
        int $count = 1,
        ImageGenerationOptions|array $options = [],
    ): array {
        $this->ensureAvailable();

        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $optionsArray = $options->toArray();
        $model = is_string($optionsArray['model'] ?? null) ? $optionsArray['model'] : self::DEFAULT_MODEL;
        $size = is_string($optionsArray['size'] ?? null) ? $optionsArray['size'] : self::DEFAULT_SIZE;
        $quality = is_string($optionsArray['quality'] ?? null) ? $optionsArray['quality'] : 'standard';
        $style = is_string($optionsArray['style'] ?? null) ? $optionsArray['style'] : 'vivid';

        // DALL-E 3 only supports n=1, need multiple requests
        if ($model === 'dall-e-3') {
            $results = [];
            for ($i = 0; $i < $count; $i++) {
                $results[] = $this->generate($prompt, $options);
            }
            return $results;
        }

        // DALL-E 2 supports n up to 10
        $count = min($count, 10);

        $this->validatePrompt($prompt, $model);

        $payload = $this->buildGeneratePayload($prompt, $optionsArray);
        $payload['n'] = $count;

        $response = $this->sendJsonRequest('generations', $payload);

        $results = [];
        /** @var array<int, array{url?: string, b64_json?: string, revised_prompt?: string}> $responseData */
        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        foreach ($responseData as $data) {
            $results[] = new ImageGenerationResult(
                url: $data['url'] ?? '',
                base64: $data['b64_json'] ?? null,
                prompt: $prompt,
                revisedPrompt: $data['revised_prompt'] ?? null,
                model: $model,
                size: $size,
                provider: 'dall-e',
                metadata: [
                    'quality' => $quality,
                    'style' => $style,
                ],
            );
        }

        $this->usageTracker->trackUsage('image', 'dall-e:' . $model, [
            'size' => $size,
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Create variations of an image (DALL-E 2 only).
     *
     * @param string $imagePath Path to source image (PNG, max 4MB, square)
     * @param int    $count     Number of variations (1-10)
     * @param string $size      Output size
     *
     * @throws ServiceUnavailableException
     *
     * @return array<int, ImageGenerationResult> Variation results
     */
    public function createVariations(
        string $imagePath,
        int $count = 1,
        string $size = '1024x1024',
    ): array {
        $this->ensureAvailable();

        $this->validateImageFile($imagePath);

        $count = min(max($count, 1), 10);

        $response = $this->sendImageMultipart('variations', $imagePath, null, [
            'n' => (string)$count,
            'size' => $size,
            'response_format' => 'url',
        ]);

        $results = [];
        /** @var array<int, array{url?: string, b64_json?: string}> $responseData */
        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        foreach ($responseData as $data) {
            $results[] = new ImageGenerationResult(
                url: $data['url'] ?? '',
                base64: $data['b64_json'] ?? null,
                prompt: '[variation of uploaded image]',
                revisedPrompt: null,
                model: 'dall-e-2',
                size: $size,
                provider: 'dall-e',
                metadata: ['type' => 'variation'],
            );
        }

        $this->usageTracker->trackUsage('image', 'dall-e:variations', [
            'size' => $size,
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Edit an image with a prompt (DALL-E 2 only).
     *
     * @param string      $imagePath Path to source image (PNG, max 4MB, square)
     * @param string      $prompt    Description of the edit
     * @param string|null $maskPath  Path to mask image (transparent areas will be edited)
     * @param string      $size      Output size
     *
     * @throws ServiceUnavailableException
     *
     * @return ImageGenerationResult Edit result
     */
    public function edit(
        string $imagePath,
        string $prompt,
        ?string $maskPath = null,
        string $size = '1024x1024',
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $this->validateImageFile($imagePath);
        if ($maskPath !== null) {
            $this->validateImageFile($maskPath);
        }

        $response = $this->sendImageMultipart('edits', $imagePath, $maskPath, [
            'prompt' => $prompt,
            'size' => $size,
            'response_format' => 'url',
        ]);

        /** @var array<int, array{url?: string, b64_json?: string}> $responseData */
        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $data = $responseData[0] ?? [];

        $this->usageTracker->trackUsage('image', 'dall-e:edit', [
            'size' => $size,
        ]);

        return new ImageGenerationResult(
            url: $data['url'] ?? '',
            base64: $data['b64_json'] ?? null,
            prompt: $prompt,
            revisedPrompt: null,
            model: 'dall-e-2',
            size: $size,
            provider: 'dall-e',
            metadata: ['type' => 'edit'],
        );
    }

    /**
     * Get available models.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableModels(): array
    {
        return self::MODEL_CAPABILITIES;
    }

    /**
     * Get supported sizes for a model.
     *
     * @return array<int, string>
     */
    public function getSupportedSizes(string $model = 'dall-e-3'): array
    {
        return self::MODEL_CAPABILITIES[$model]['sizes'] ?? ['1024x1024'];
    }

    protected function getServiceDomain(): string
    {
        return 'image';
    }

    protected function getServiceProvider(): string
    {
        return 'dall-e';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::API_URL;
    }

    protected function getDefaultTimeout(): int
    {
        return 120;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function loadServiceConfiguration(array $config): void
    {
        // is_string() guards: the extension config tree is user-editable
        // YAML and the @var hint above describes the documented shape,
        // not a runtime guarantee. Direct assignment without guards
        // would TypeError on `non-string apiKey` and defeat the
        // base's fail-soft contract.
        $apiKey  = $this->resolveScalarConfig($config, ['providers', 'openai', 'apiKey']);
        $baseUrl = $this->resolveScalarConfig($config, ['image', 'dalle', 'baseUrl']);
        $timeout = $this->resolveScalarConfig($config, ['image', 'dalle', 'timeout']);

        $this->apiKey  = is_string($apiKey) ? $apiKey : '';
        $this->baseUrl = is_string($baseUrl) ? $baseUrl : self::API_URL;
        $this->timeout = is_numeric($timeout) ? (int)$timeout : $this->getDefaultTimeout();
    }

    /**
     * Walk a nested array path safely. Returns the leaf value or null
     * if any intermediate hop is missing / not an array.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $path
     */
    private function resolveScalarConfig(array $config, array $path): mixed
    {
        $current = $config;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    protected function buildAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * DALL-E surfaces a 400 validation error distinctly from generic
     * 4xx — keeps the existing exception payload (`type => 'validation'`)
     * so downstream catches that branched on it continue to work.
     */
    protected function mapErrorStatus(int $statusCode, string $errorMessage): Throwable
    {
        if ($statusCode === 400) {
            return new ServiceUnavailableException(
                'DALL-E API error: ' . $errorMessage,
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider(), 'type' => 'validation'],
            );
        }
        return parent::mapErrorStatus($statusCode, $errorMessage);
    }

    protected function getProviderLabel(): string
    {
        return 'DALL-E';
    }

    /**
     * Validate prompt for model.
     *
     * @throws ServiceUnavailableException
     */
    private function validatePrompt(string $prompt, string $model): void
    {
        if (empty(trim($prompt))) {
            throw new ServiceUnavailableException(
                'Prompt cannot be empty',
                'image',
                ['provider' => 'dall-e'],
            );
        }

        $maxLength = self::MODEL_CAPABILITIES[$model]['max_prompt_length'] ?? 4000;

        if (mb_strlen($prompt) > $maxLength) {
            throw new ServiceUnavailableException(
                sprintf('Prompt exceeds maximum length of %d characters for %s', $maxLength, $model),
                'image',
                ['provider' => 'dall-e', 'length' => mb_strlen($prompt)],
            );
        }
    }

    /**
     * Validate image file for editing/variations.
     *
     * @throws ServiceUnavailableException
     */
    private function validateImageFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new ServiceUnavailableException(
                sprintf('Image file not found: %s', $path),
                'image',
                ['provider' => 'dall-e'],
            );
        }

        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > 4 * 1024 * 1024) {
            throw new ServiceUnavailableException(
                'Image file must be less than 4MB',
                'image',
                ['provider' => 'dall-e'],
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'png') {
            throw new ServiceUnavailableException(
                'Image must be a PNG file',
                'image',
                ['provider' => 'dall-e'],
            );
        }
    }

    /**
     * Build generation request payload.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildGeneratePayload(string $prompt, array $options): array
    {
        $model = $options['model'] ?? self::DEFAULT_MODEL;

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $options['size'] ?? self::DEFAULT_SIZE,
            'response_format' => $options['response_format'] ?? 'url',
        ];

        // DALL-E 3 specific options
        if ($model === 'dall-e-3') {
            if (isset($options['quality'])) {
                $payload['quality'] = $options['quality'];
            }
            if (isset($options['style'])) {
                $payload['style'] = $options['style'];
            }
        }

        return $payload;
    }

    /**
     * Build the parts list for an image edit / variation request and
     * dispatch via the shared multipart sender. Wraps the trait's
     * generic part-shape API in a DALL-E-specific signature so the
     * call sites (`createVariations`, `edit`) stay readable.
     *
     * @param array<string, scalar|null> $fields
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    private function sendImageMultipart(
        string $endpoint,
        string $imagePath,
        ?string $maskPath,
        array $fields,
    ): array {
        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new ServiceUnavailableException(
                sprintf('Failed to read image file: %s', $imagePath),
                'image',
                ['provider' => 'dall-e', 'path' => $imagePath],
            );
        }

        $parts = [
            ['name' => 'image', 'filename' => 'image.png', 'content' => $imageContent, 'contentType' => 'image/png'],
        ];

        if ($maskPath !== null) {
            $maskContent = file_get_contents($maskPath);
            if ($maskContent === false) {
                throw new ServiceUnavailableException(
                    sprintf('Failed to read mask file: %s', $maskPath),
                    'image',
                    ['provider' => 'dall-e', 'path' => $maskPath],
                );
            }
            $parts[] = ['name' => 'mask', 'filename' => 'mask.png', 'content' => $maskContent, 'contentType' => 'image/png'];
        }

        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'value' => $value ?? ''];
        }

        return $this->sendMultipartRequest($endpoint, $parts);
    }
}
