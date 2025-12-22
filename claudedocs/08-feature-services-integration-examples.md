# Feature Services Integration Examples

> Created: 2025-12-22
> Purpose: Practical integration examples for consuming extensions

---

## Overview

This document provides complete integration examples showing how the three main Netresearch extensions (rte-ckeditor-image, textdb, contexts) consume the nr-llm feature services.

---

## 1. rte-ckeditor-image Integration

### Use Case: AI-Powered Image Metadata Generation

The rte-ckeditor-image extension needs to generate alt text, titles, and descriptions for images uploaded through the CMS.

### Service Implementation

```php
<?php

declare(strict_types=1);

namespace Netresearch\RteCkeditorImage\Service;

use Netresearch\NrLlm\Service\Feature\VisionService;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\File;

/**
 * AI-powered image metadata generation service
 */
class ImageAiEnhancementService
{
    public function __construct(
        private readonly VisionService $visionService,
    ) {}

    /**
     * Generate all metadata for a single image
     *
     * @param FileReference $fileReference
     * @return array ['alt' => string, 'title' => string, 'description' => string]
     */
    public function enhanceImageMetadata(FileReference $fileReference): array
    {
        $imageUrl = $this->getPublicUrl($fileReference);

        return [
            'alt' => $this->visionService->generateAltText($imageUrl),
            'title' => $this->visionService->generateTitle($imageUrl),
            'description' => $this->visionService->generateDescription($imageUrl),
        ];
    }

    /**
     * Batch process multiple images efficiently
     *
     * @param array $fileReferences Array of FileReference objects
     * @return array Indexed results matching input order
     */
    public function enhanceMultipleImages(array $fileReferences): array
    {
        $imageUrls = array_map(
            fn(FileReference $ref) => $this->getPublicUrl($ref),
            $fileReferences
        );

        // Process all images in parallel
        $altTexts = $this->visionService->generateAltText($imageUrls);
        $titles = $this->visionService->generateTitle($imageUrls);
        $descriptions = $this->visionService->generateDescription($imageUrls);

        // Combine results
        $results = [];
        foreach ($fileReferences as $index => $ref) {
            $results[$ref->getUid()] = [
                'alt' => $altTexts[$index],
                'title' => $titles[$index],
                'description' => $descriptions[$index],
            ];
        }

        return $results;
    }

    /**
     * Generate only missing metadata fields
     *
     * @param FileReference $fileReference
     * @return array Updated metadata
     */
    public function fillMissingMetadata(FileReference $fileReference): array
    {
        $imageUrl = $this->getPublicUrl($fileReference);
        $metadata = [];

        if (empty($fileReference->getAlternative())) {
            $metadata['alt'] = $this->visionService->generateAltText($imageUrl);
        }

        if (empty($fileReference->getTitle())) {
            $metadata['title'] = $this->visionService->generateTitle($imageUrl);
        }

        if (empty($fileReference->getDescription())) {
            $metadata['description'] = $this->visionService->generateDescription($imageUrl);
        }

        return $metadata;
    }

    /**
     * Custom image analysis for specific contexts
     *
     * @param FileReference $fileReference
     * @param string $context e.g., 'product', 'team', 'logo'
     * @return array Context-specific metadata
     */
    public function analyzeImageForContext(FileReference $fileReference, string $context): array
    {
        $imageUrl = $this->getPublicUrl($fileReference);

        $prompts = [
            'product' => 'Describe this product image focusing on features, colors, and selling points.',
            'team' => 'Identify people in this team photo and describe the setting.',
            'logo' => 'Describe this logo including colors, style, and any text or symbols.',
            'chart' => 'Analyze this chart or graph and explain the data trends shown.',
        ];

        $customPrompt = $prompts[$context] ?? 'Describe this image in detail.';

        return [
            'analysis' => $this->visionService->analyzeImage($imageUrl, $customPrompt),
        ];
    }

    /**
     * Get public URL for image
     */
    private function getPublicUrl(FileReference $fileReference): string
    {
        $file = $fileReference->getOriginalFile();
        return $file->getPublicUrl(true); // Absolute URL
    }
}
```

### Backend Controller Integration

```php
<?php

namespace Netresearch\RteCkeditorImage\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Http\JsonResponse;

class ImageMetadataController
{
    public function __construct(
        private readonly ImageAiEnhancementService $aiService,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * AJAX endpoint for generating image metadata
     */
    public function generateMetadataAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getParsedBody();
        $fileUid = (int) ($params['fileUid'] ?? 0);

        if ($fileUid === 0) {
            return new JsonResponse(['error' => 'Invalid file UID'], 400);
        }

        try {
            $fileReference = $this->resourceFactory->getFileReferenceObject($fileUid);
            $metadata = $this->aiService->enhanceImageMetadata($fileReference);

            return new JsonResponse([
                'success' => true,
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch endpoint for multiple images
     */
    public function generateBatchMetadataAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getParsedBody();
        $fileUids = $params['fileUids'] ?? [];

        $fileReferences = array_map(
            fn($uid) => $this->resourceFactory->getFileReferenceObject((int) $uid),
            $fileUids
        );

        $results = $this->aiService->enhanceMultipleImages($fileReferences);

        return new JsonResponse([
            'success' => true,
            'results' => $results,
        ]);
    }
}
```

---

## 2. textdb Extension Integration

### Use Case: AI-Powered Translation Suggestions with Semantic Search

The textdb extension manages translation databases and needs AI assistance for translation suggestions and finding similar translations from memory.

### Service Implementation

```php
<?php

declare(strict_types=1);

namespace Netresearch\Textdb\Service;

use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;

/**
 * AI-powered translation assistance service
 */
class AiTranslationAssistanceService
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly EmbeddingService $embeddingService,
        private readonly TranslationMemoryRepository $memoryRepository,
    ) {}

    /**
     * Suggest translation with AI assistance
     *
     * @param string $sourceText Source text to translate
     * @param string $targetLanguage Target language code
     * @param array $context Additional context
     * @return array Translation suggestions with confidence scores
     */
    public function suggestTranslation(
        string $sourceText,
        string $targetLanguage,
        array $context = []
    ): array {
        // 1. Find similar translations from memory
        $similarTranslations = $this->findSimilarTranslations($sourceText, $targetLanguage, 3);

        // 2. Build glossary from terminology database
        $glossary = $this->buildGlossary($sourceText, $targetLanguage);

        // 3. Get AI translation with context
        $aiResult = $this->translationService->translate(
            text: $sourceText,
            targetLanguage: $targetLanguage,
            options: [
                'glossary' => $glossary,
                'context' => $this->buildContext($context, $similarTranslations),
                'formality' => $context['formality'] ?? 'default',
                'domain' => $context['domain'] ?? 'general',
            ]
        );

        return [
            'ai_suggestion' => [
                'text' => $aiResult->getText(),
                'confidence' => $aiResult->confidence,
                'source_language' => $aiResult->sourceLanguage,
            ],
            'memory_matches' => $similarTranslations,
            'glossary_terms' => $glossary,
        ];
    }

    /**
     * Find similar translations using semantic search
     *
     * @param string $sourceText
     * @param string $targetLanguage
     * @param int $limit
     * @return array Similar translation memory entries
     */
    public function findSimilarTranslations(
        string $sourceText,
        string $targetLanguage,
        int $limit = 5
    ): array {
        // Generate embedding for source text
        $queryVector = $this->embeddingService->embed($sourceText);

        // Get all translation memory entries for target language
        $memoryEntries = $this->memoryRepository->findByTargetLanguage($targetLanguage);

        // Generate embeddings for memory entries (cached)
        $memoryTexts = array_map(fn($entry) => $entry->getSourceText(), $memoryEntries);
        $memoryVectors = $this->embeddingService->embedBatch($memoryTexts);

        // Find most similar
        $similar = $this->embeddingService->findMostSimilar(
            $queryVector,
            $memoryVectors,
            $limit
        );

        // Map back to translation entries with similarity scores
        return array_map(
            fn($match) => [
                'source' => $memoryEntries[$match['index']]->getSourceText(),
                'target' => $memoryEntries[$match['index']]->getTargetText(),
                'similarity' => round($match['similarity'], 3),
                'context' => $memoryEntries[$match['index']]->getContext(),
            ],
            $similar
        );
    }

    /**
     * Batch translate multiple texts efficiently
     *
     * @param array $texts Array of source texts
     * @param string $targetLanguage Target language code
     * @param array $options Translation options
     * @return array Translation results
     */
    public function batchTranslate(
        array $texts,
        string $targetLanguage,
        array $options = []
    ): array {
        $results = $this->translationService->translateBatch(
            texts: $texts,
            targetLanguage: $targetLanguage,
            options: $options
        );

        return array_map(
            fn($result) => [
                'translation' => $result->getText(),
                'confidence' => $result->confidence,
                'alternatives' => $result->getAlternatives(),
            ],
            $results
        );
    }

    /**
     * Score translation quality
     *
     * @param string $sourceText
     * @param string $translatedText
     * @param string $targetLanguage
     * @return float Quality score (0.0-1.0)
     */
    public function scoreTranslationQuality(
        string $sourceText,
        string $translatedText,
        string $targetLanguage
    ): float {
        return $this->translationService->scoreTranslationQuality(
            $sourceText,
            $translatedText,
            $targetLanguage
        );
    }

    /**
     * Build glossary from terminology database
     */
    private function buildGlossary(string $sourceText, string $targetLanguage): array
    {
        // Extract terms from source text that exist in terminology database
        $terms = $this->memoryRepository->findTermsInText($sourceText, $targetLanguage);

        $glossary = [];
        foreach ($terms as $term) {
            $glossary[$term->getSourceTerm()] = $term->getTargetTerm();
        }

        return $glossary;
    }

    /**
     * Build context from similar translations
     */
    private function buildContext(array $context, array $similarTranslations): string
    {
        $contextParts = [];

        if (!empty($context['surrounding_text'])) {
            $contextParts[] = "Surrounding content: " . $context['surrounding_text'];
        }

        if (!empty($similarTranslations)) {
            $contextParts[] = "Similar translations:";
            foreach (array_slice($similarTranslations, 0, 2) as $match) {
                $contextParts[] = sprintf(
                    "- \"%s\" → \"%s\" (%.0f%% match)",
                    $match['source'],
                    $match['target'],
                    $match['similarity'] * 100
                );
            }
        }

        return implode("\n", $contextParts);
    }
}
```

---

## 3. contexts Extension Integration

### Use Case: AI-Powered Context Rule Generation

The contexts extension manages conditional content display rules and needs AI to convert natural language descriptions into rule configurations.

### Service Implementation

```php
<?php

declare(strict_types=1);

namespace Netresearch\Contexts\Service;

use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\PromptTemplateService;

/**
 * AI-powered context rule generation service
 */
class AiRuleGeneratorService
{
    public function __construct(
        private readonly CompletionService $completionService,
        private readonly PromptTemplateService $promptService,
    ) {}

    /**
     * Generate context rule from natural language description
     *
     * @param string $description Natural language rule description
     * @return array Rule configuration or null if invalid
     */
    public function generateRule(string $description): ?array
    {
        $prompt = $this->promptService->render(
            'completion.rule_generation',
            [
                'description' => $description,
                'schema' => $this->getRuleSchema(),
            ]
        );

        try {
            $rule = $this->completionService->completeJson(
                prompt: $prompt->getUserPrompt(),
                options: [
                    'system_prompt' => $prompt->getSystemPrompt(),
                    'temperature' => 0.2, // Low temperature for consistency
                    'max_tokens' => 1000,
                ]
            );

            // Validate generated rule
            if ($this->validateRule($rule)) {
                return $rule;
            }

            return null;
        } catch (\Exception $e) {
            // Log error and return null
            return null;
        }
    }

    /**
     * Generate multiple rule variations
     *
     * @param string $description
     * @param int $count Number of variations
     * @return array Array of rule configurations
     */
    public function generateRuleVariations(string $description, int $count = 3): array
    {
        $variations = [];

        for ($i = 0; $i < $count; $i++) {
            $rule = $this->generateRule($description);
            if ($rule !== null) {
                $variations[] = $rule;
            }
        }

        return $variations;
    }

    /**
     * Explain existing rule in natural language
     *
     * @param array $rule Rule configuration
     * @return string Human-readable explanation
     */
    public function explainRule(array $rule): string
    {
        $ruleJson = json_encode($rule, JSON_PRETTY_PRINT);

        $response = $this->completionService->complete(
            prompt: "Explain this TYPO3 context rule in simple, natural language:\n\n" . $ruleJson,
            options: [
                'system_prompt' => 'You are a TYPO3 expert. Explain technical configurations in clear, simple language for editors.',
                'temperature' => 0.4,
                'max_tokens' => 300,
            ]
        );

        return $response->text;
    }

    /**
     * Suggest improvements to existing rule
     *
     * @param array $rule Current rule configuration
     * @return array Improved rule with explanation
     */
    public function suggestImprovements(array $rule): array
    {
        $ruleJson = json_encode($rule, JSON_PRETTY_PRINT);

        $response = $this->completionService->completeJson(
            prompt: "Analyze this TYPO3 context rule and suggest improvements:\n\n" . $ruleJson,
            options: [
                'system_prompt' => 'You are a TYPO3 expert. Analyze context rules and suggest improvements for performance, clarity, and best practices. Respond with JSON: {"improved_rule": {...}, "changes": ["change 1", "change 2"], "reasoning": "explanation"}',
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ]
        );

        return $response;
    }

    /**
     * Get rule schema for prompt
     */
    private function getRuleSchema(): string
    {
        return json_encode([
            'type' => 'object',
            'required' => ['name', 'conditions'],
            'properties' => [
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'conditions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => ['type' => 'string', 'enum' => ['equals', 'contains', 'greater_than', 'less_than']],
                            'value' => ['type' => 'string'],
                        ],
                    ],
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'target' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Validate generated rule
     */
    private function validateRule(array $rule): bool
    {
        // Check required fields
        if (!isset($rule['name']) || !isset($rule['conditions'])) {
            return false;
        }

        // Validate conditions
        if (!is_array($rule['conditions']) || empty($rule['conditions'])) {
            return false;
        }

        foreach ($rule['conditions'] as $condition) {
            if (!isset($condition['field']) || !isset($condition['operator']) || !isset($condition['value'])) {
                return false;
            }
        }

        return true;
    }
}
```

---

## 4. Dependency Injection Configuration

### rte-ckeditor-image

```yaml
# ext_rte_ckeditor_image/Configuration/Services.yaml

services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\RteCkeditorImage\:
    resource: '../Classes/*'

  Netresearch\RteCkeditorImage\Service\ImageAiEnhancementService:
    public: true
    arguments:
      $visionService: '@Netresearch\NrLlm\Service\Feature\VisionService'
```

### textdb

```yaml
# ext_textdb/Configuration/Services.yaml

services:
  Netresearch\Textdb\Service\AiTranslationAssistanceService:
    public: true
    arguments:
      $translationService: '@Netresearch\NrLlm\Service\Feature\TranslationService'
      $embeddingService: '@Netresearch\NrLlm\Service\Feature\EmbeddingService'
      $memoryRepository: '@Netresearch\Textdb\Domain\Repository\TranslationMemoryRepository'
```

### contexts

```yaml
# ext_contexts/Configuration/Services.yaml

services:
  Netresearch\Contexts\Service\AiRuleGeneratorService:
    public: true
    arguments:
      $completionService: '@Netresearch\NrLlm\Service\Feature\CompletionService'
      $promptService: '@Netresearch\NrLlm\Service\PromptTemplateService'
```

---

## 5. Performance Considerations

### Caching Strategy

```php
// Example: Cache image metadata generation results
class ImageAiEnhancementService
{
    public function enhanceImageMetadata(FileReference $fileReference): array
    {
        $cacheKey = 'image_ai_' . $fileReference->getUid() . '_' . $fileReference->getOriginalFile()->getSha1();

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $metadata = [
            'alt' => $this->visionService->generateAltText($imageUrl),
            'title' => $this->visionService->generateTitle($imageUrl),
            'description' => $this->visionService->generateDescription($imageUrl),
        ];

        // Cache for 30 days (image content rarely changes)
        $this->cache->set($cacheKey, $metadata, 2592000);

        return $metadata;
    }
}
```

### Batch Processing

```php
// Process multiple images in one request instead of individual calls
$results = $aiService->enhanceMultipleImages($fileReferences);
// vs. individual calls (slower):
foreach ($fileReferences as $ref) {
    $result = $aiService->enhanceImageMetadata($ref);
}
```

---

## Summary

These integration examples demonstrate:

1. **Clean separation of concerns**: Feature services handle AI logic, consuming extensions handle domain logic
2. **Type safety**: Strong typing throughout the integration chain
3. **Testability**: Easy to mock services for unit testing
4. **Performance**: Batch processing and caching strategies
5. **Flexibility**: Customizable prompts and configurations per use case

All three extensions benefit from shared infrastructure while maintaining their specific domain requirements.
