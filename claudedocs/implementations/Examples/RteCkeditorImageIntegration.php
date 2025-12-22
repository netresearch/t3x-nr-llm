<?php

declare(strict_types=1);

namespace Netresearch\RteCkeditorImage\Service;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Exception\LlmException;
use Psr\Log\LoggerInterface;

/**
 * RTE CKEditor Image AI Service Integration Example
 *
 * Shows how rte-ckeditor-image extension uses LlmServiceManager for:
 * - Automatic alt text generation
 * - Image description creation
 * - Accessibility compliance
 */
class ImageAiService
{
    public function __construct(
        private readonly LlmServiceManager $llm,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate alt text for image
     *
     * @param string $imageUrl Image URL or path
     * @param int $maxLength Maximum alt text length
     * @param string $style 'brief'|'descriptive'|'seo-optimized'
     * @return string Generated alt text
     */
    public function generateAltText(
        string $imageUrl,
        int $maxLength = 125,
        string $style = 'descriptive'
    ): string {
        $styleInstructions = match ($style) {
            'brief' => 'Be concise and factual. Focus on key elements only.',
            'descriptive' => 'Provide clear, detailed description for accessibility.',
            'seo-optimized' => 'Include relevant keywords while remaining natural.',
            default => 'Provide a balanced description.'
        };

        $prompt = <<<PROMPT
Generate alt text for this image (maximum {$maxLength} characters).

Style: {$styleInstructions}

Requirements:
- Clear and concise
- WCAG 2.1 compliant
- No "image of" or "picture of" prefix
- Focus on content, not presentation
PROMPT;

        try {
            $response = $this->llm
                ->setProvider('openai')  // Force GPT-4V for vision
                ->withCache(true, 2592000)  // Cache for 30 days
                ->analyzeImage($imageUrl, $prompt);

            $altText = $response->getDescription();

            // Ensure max length
            if (strlen($altText) > $maxLength) {
                $altText = substr($altText, 0, $maxLength - 3) . '...';
            }

            return $altText;
        } catch (LlmException $e) {
            $this->logger->error('Alt text generation failed', [
                'image' => $imageUrl,
                'error' => $e->getMessage()
            ]);

            return '';
        }
    }

    /**
     * Generate full image analysis with metadata
     *
     * @param string $imageUrl Image URL
     * @return array Complete image analysis
     */
    public function analyzeImage(string $imageUrl): array
    {
        $prompt = <<<PROMPT
Analyze this image and provide:

1. Alt text (concise, WCAG compliant)
2. Title text (for tooltip, 50-60 chars)
3. Detailed description (2-3 sentences)
4. Objects detected (array)
5. Scene type and setting
6. Dominant colors
7. Accessibility notes

Respond in JSON format:
{
    "alt_text": "...",
    "title": "...",
    "description": "...",
    "objects": ["object1", "object2"],
    "scene": {"type": "...", "setting": "..."},
    "colors": ["#hex1", "#hex2"],
    "accessibility": {"contrast": "good|poor", "notes": "..."}
}
PROMPT;

        try {
            $response = $this->llm
                ->setProvider('openai')
                ->withCache(true, 2592000)
                ->withOptions(['response_format' => 'json'])
                ->analyzeImage($imageUrl, $prompt);

            $analysis = json_decode($response->getContent(), true);

            return [
                'success' => true,
                'data' => $analysis,
                'metadata' => [
                    'confidence' => $response->getConfidence(),
                    'tokens' => $response->getTotalTokens(),
                    'cost' => $response->getCostEstimate()
                ]
            ];
        } catch (LlmException $e) {
            $this->logger->error('Image analysis failed', [
                'image' => $imageUrl,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate contextual alt text using page content
     *
     * @param string $imageUrl Image URL
     * @param string $pageContext Surrounding text content
     * @return string Context-aware alt text
     */
    public function generateContextualAltText(
        string $imageUrl,
        string $pageContext
    ): string {
        $prompt = <<<PROMPT
Generate alt text for this image considering the surrounding page content:

Page context: {$pageContext}

Requirements:
- Complement the surrounding text (don't repeat information)
- Maximum 125 characters
- WCAG 2.1 compliant
- Natural and descriptive
PROMPT;

        try {
            $response = $this->llm
                ->setProvider('openai')
                ->withCache(true, 604800)  // Cache for 7 days (context-specific)
                ->analyzeImage($imageUrl, $prompt);

            return substr($response->getDescription(), 0, 125);
        } catch (LlmException $e) {
            $this->logger->error('Contextual alt text generation failed', [
                'error' => $e->getMessage()
            ]);

            // Fallback to non-contextual
            return $this->generateAltText($imageUrl);
        }
    }

    /**
     * Batch process images for alt text
     *
     * @param array $images Array of image URLs
     * @param callable $progressCallback Progress callback function
     * @return array Batch results
     */
    public function batchGenerateAltText(
        array $images,
        ?callable $progressCallback = null
    ): array {
        $results = [];
        $total = count($images);
        $processed = 0;

        foreach ($images as $imageUrl) {
            try {
                $altText = $this->generateAltText($imageUrl);

                $results[$imageUrl] = [
                    'success' => true,
                    'alt_text' => $altText
                ];
            } catch (\Exception $e) {
                $results[$imageUrl] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }

            $processed++;

            if ($progressCallback !== null) {
                $progressCallback($processed, $total, $imageUrl);
            }

            // Rate limiting delay
            usleep(500000);  // 500ms
        }

        return $results;
    }
}
