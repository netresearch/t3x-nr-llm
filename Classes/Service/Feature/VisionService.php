<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\PromptTemplateService;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * High-level service for image analysis and vision tasks
 *
 * Provides specialized image analysis with accessibility,
 * SEO, and descriptive prompts.
 */
class VisionService
{
    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly PromptTemplateService $promptService,
    ) {}

    /**
     * Generate accessibility-focused alt text for image
     *
     * Optimized for screen readers and WCAG 2.1 Level AA compliance.
     * Output is concise (under 125 characters) and focuses on essential information.
     *
     * @param string|array $imageUrl Single URL or array of URLs
     * @param array $options Configuration options:
     *   - detail_level: string ('auto'|'low'|'high') Vision API detail
     *   - max_tokens: int Maximum output tokens, default 100
     *   - temperature: float Creativity level, default 0.5
     *   - batch_mode: bool Process multiple images efficiently
     * @return string|array Alt text(s)
     */
    public function generateAltText(string|array $imageUrl, array $options = []): string|array
    {
        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, 'vision.alt_text', $options);
        }

        return $this->processImage($imageUrl, 'vision.alt_text', $options);
    }

    /**
     * Generate SEO-optimized title for image
     *
     * Creates compelling, keyword-rich titles under 60 characters
     * for improved search rankings.
     *
     * @param string|array $imageUrl Single URL or array of URLs
     * @param array $options Configuration options (same as generateAltText)
     * @return string|array Title(s)
     */
    public function generateTitle(string|array $imageUrl, array $options = []): string|array
    {
        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, 'vision.seo_title', $options);
        }

        return $this->processImage($imageUrl, 'vision.seo_title', $options);
    }

    /**
     * Generate detailed description of image
     *
     * Provides comprehensive analysis including subjects, setting,
     * colors, mood, composition, and notable details.
     *
     * @param string|array $imageUrl Single URL or array of URLs
     * @param array $options Configuration options (same as generateAltText)
     * @return string|array Description(s)
     */
    public function generateDescription(string|array $imageUrl, array $options = []): string|array
    {
        if (is_array($imageUrl)) {
            return $this->processBatch($imageUrl, 'vision.description', $options);
        }

        return $this->processImage($imageUrl, 'vision.description', $options);
    }

    /**
     * Analyze image with custom prompt
     *
     * Allows arbitrary image analysis queries with user-defined prompts.
     *
     * @param string|array $imageUrl Single URL or array of URLs
     * @param string $customPrompt Custom analysis prompt
     * @param array $options Configuration options (same as generateAltText)
     * @return string|array Analysis result(s)
     */
    public function analyzeImage(
        string|array $imageUrl,
        string $customPrompt,
        array $options = []
    ): string|array {
        if (is_array($imageUrl)) {
            return $this->processBatchCustom($imageUrl, $customPrompt, $options);
        }

        return $this->processImageCustom($imageUrl, $customPrompt, $options);
    }

    /**
     * Analyze image with full response object
     *
     * Returns complete VisionResponse with metadata and usage statistics.
     *
     * @param string $imageUrl Image URL
     * @param string $promptIdentifier Prompt template identifier
     * @param array $options Configuration options
     * @return VisionResponse
     */
    public function analyzeImageFull(
        string $imageUrl,
        string $promptIdentifier,
        array $options = []
    ): VisionResponse {
        $this->validateImageUrl($imageUrl);

        $prompt = $this->promptService->render($promptIdentifier, [
            'image_url' => $imageUrl,
        ]);

        $requestOptions = [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt->getSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt->getUserPrompt(),
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $imageUrl,
                                'detail' => $options['detail_level'] ?? 'auto',
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => $options['temperature'] ?? $prompt->getTemperature(),
            'max_tokens' => $options['max_tokens'] ?? $prompt->getMaxTokens(),
        ];

        $response = $this->llmManager->complete($requestOptions);

        return new VisionResponse(
            analysis: $response->getContent(),
            usage: UsageStatistics::fromTokens(
                promptTokens: $response->getUsage()['prompt_tokens'] ?? 0,
                completionTokens: $response->getUsage()['completion_tokens'] ?? 0,
                estimatedCost: $response->getUsage()['estimated_cost'] ?? null
            ),
            confidence: $response->getMetadata()['confidence'] ?? null,
            detectedObjects: $response->getMetadata()['objects'] ?? null,
            metadata: $response->getMetadata()
        );
    }

    /**
     * Process single image with template
     *
     * @param string $imageUrl
     * @param string $promptIdentifier
     * @param array $options
     * @return string
     */
    private function processImage(
        string $imageUrl,
        string $promptIdentifier,
        array $options
    ): string {
        $response = $this->analyzeImageFull($imageUrl, $promptIdentifier, $options);
        return $response->getText();
    }

    /**
     * Process single image with custom prompt
     *
     * @param string $imageUrl
     * @param string $customPrompt
     * @param array $options
     * @return string
     */
    private function processImageCustom(
        string $imageUrl,
        string $customPrompt,
        array $options
    ): string {
        $this->validateImageUrl($imageUrl);

        $requestOptions = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $customPrompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $imageUrl,
                                'detail' => $options['detail_level'] ?? 'auto',
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => $options['temperature'] ?? 0.5,
            'max_tokens' => $options['max_tokens'] ?? 300,
        ];

        $response = $this->llmManager->complete($requestOptions);

        return $response->getContent();
    }

    /**
     * Process batch of images with template
     *
     * @param array $imageUrls
     * @param string $promptIdentifier
     * @param array $options
     * @return array
     */
    private function processBatch(
        array $imageUrls,
        string $promptIdentifier,
        array $options
    ): array {
        $results = [];

        foreach ($imageUrls as $imageUrl) {
            $results[] = $this->processImage($imageUrl, $promptIdentifier, $options);
        }

        return $results;
    }

    /**
     * Process batch of images with custom prompt
     *
     * @param array $imageUrls
     * @param string $customPrompt
     * @param array $options
     * @return array
     */
    private function processBatchCustom(
        array $imageUrls,
        string $customPrompt,
        array $options
    ): array {
        $results = [];

        foreach ($imageUrls as $imageUrl) {
            $results[] = $this->processImageCustom($imageUrl, $customPrompt, $options);
        }

        return $results;
    }

    /**
     * Validate image URL
     *
     * @param string $imageUrl
     * @throws InvalidArgumentException
     */
    private function validateImageUrl(string $imageUrl): void
    {
        // Check if it's a valid URL or base64 data URI
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            // Check for base64 data URI
            if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/', $imageUrl)) {
                throw new InvalidArgumentException(
                    'Invalid image URL or base64 data URI'
                );
            }
        }
    }
}
