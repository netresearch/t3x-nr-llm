<?php

declare(strict_types=1);

namespace Netresearch\NrTextdb\Service;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Exception\LlmException;
use Netresearch\NrLlm\Exception\QuotaExceededException;
use Psr\Log\LoggerInterface;

/**
 * TextDB AI Translation Service Integration Example
 *
 * Shows how textdb extension uses LlmServiceManager for:
 * - Quick translation suggestions
 * - Bulk translation generation
 * - Quality checks
 */
class TranslationAiService
{
    public function __construct(
        private readonly LlmServiceManager $llm,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate translation suggestion for single text
     *
     * @param string $sourceText Source text to translate
     * @param string $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code
     * @param array $context Additional context (component, type, etc.)
     * @return array Translation data with alternatives
     */
    public function suggestTranslation(
        string $sourceText,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $context = []
    ): array {
        try {
            $response = $this->llm
                ->withCache(true, 86400)  // Cache for 24h
                ->translate($sourceText, $targetLanguage, $sourceLanguage);

            return [
                'translation' => $response->getTranslation(),
                'confidence' => $response->getConfidence(),
                'alternatives' => $response->getAlternatives(),
                'metadata' => [
                    'tokens' => $response->getTotalTokens(),
                    'cost' => $response->getCostEstimate(),
                ],
            ];
        } catch (QuotaExceededException $e) {
            $this->logger->warning('Translation quota exceeded', [
                'text' => $sourceText,
                'target_lang' => $targetLanguage,
            ]);

            return [
                'error' => 'quota_exceeded',
                'message' => $e->getMessage(),
                'suggestion' => $e->getSuggestion(),
            ];
        } catch (LlmException $e) {
            $this->logger->error('Translation failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
            ]);

            return [
                'error' => 'translation_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Bulk translate missing translations for a language
     *
     * @param string $targetLanguage Target language code
     * @param array $untranslatedTexts Array of untranslated texts
     * @param string|null $component Component identifier for context
     * @return array Batch translation results
     */
    public function bulkTranslate(
        string $targetLanguage,
        array $untranslatedTexts,
        ?string $component = null
    ): array {
        $results = [];
        $totalCost = 0.0;
        $successCount = 0;
        $errorCount = 0;

        foreach ($untranslatedTexts as $key => $text) {
            try {
                $response = $this->llm
                    ->withCache(true)
                    ->translate($text, $targetLanguage);

                $results[$key] = [
                    'success' => true,
                    'translation' => $response->getTranslation(),
                    'confidence' => $response->getConfidence(),
                    'tokens' => $response->getTotalTokens(),
                ];

                $totalCost += $response->getCostEstimate();
                $successCount++;

                // Small delay to avoid rate limits
                usleep(100000);  // 100ms
            } catch (QuotaExceededException $e) {
                $this->logger->warning('Bulk translation quota exceeded at index ' . $key);
                $results[$key] = [
                    'success' => false,
                    'error' => 'quota_exceeded',
                ];
                break;  // Stop processing
            } catch (LlmException $e) {
                $this->logger->error('Translation failed for text: ' . $text, [
                    'error' => $e->getMessage(),
                ]);

                $results[$key] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $errorCount++;
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($untranslatedTexts),
                'success' => $successCount,
                'errors' => $errorCount,
                'total_cost' => $totalCost,
            ],
        ];
    }

    /**
     * Check translation quality
     *
     * @param string $translation Translation to check
     * @param string $targetLanguage Target language
     * @return array Quality report
     */
    public function checkQuality(string $translation, string $targetLanguage): array
    {
        $prompt = <<<PROMPT
            Analyze this {$targetLanguage} translation for quality:

            Translation: "{$translation}"

            Provide a quality assessment with:
            1. Grammar check (correct/incorrect)
            2. Natural language flow (1-10)
            3. Suggested improvements (if any)
            4. Overall quality score (0-100)

            Respond in JSON format:
            {
                "grammar": "correct|incorrect",
                "flow_score": 0-10,
                "improvements": ["suggestion1", "suggestion2"],
                "overall_score": 0-100,
                "notes": "brief explanation"
            }
            PROMPT;

        try {
            $response = $this->llm
                ->withOptions(['response_format' => 'json', 'temperature' => 0.3])
                ->complete($prompt);

            return json_decode($response->getContent(), true);
        } catch (LlmException $e) {
            $this->logger->error('Quality check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => true,
                'message' => 'Quality check unavailable',
            ];
        }
    }
}
