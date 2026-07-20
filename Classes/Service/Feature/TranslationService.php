<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;

/**
 * High-level service for text translation.
 *
 * Provides language translation with quality control,
 * glossary support, and context awareness.
 *
 * Supports dual-path translation:
 * - LLM-based translation (default)
 * - Specialized translators (DeepL, etc.) via TranslatorRegistry
 *
 * Budget pre-flight (REC #4 slice 15b): the LLM-based translation
 * path (translate / detectLanguage / scoreTranslationQuality) goes
 * through `LlmServiceManager::chat()` which is BudgetMiddleware-
 * aware as of slice 15a — this service forwards the budget fields
 * from `TranslationOptions` onto the internally-built `ChatOptions`
 * so the metadata reaches the pipeline. The specialized-translator
 * path (DeepL et al.) does NOT go through `LlmServiceManager`, so
 * the BudgetMiddleware does not currently apply there; ADR / future
 * slice can route specialized translators through a similar
 * pre-flight if needed. Usage *attribution* on that path is wired,
 * though: the resolved uid is re-attached to the translator options
 * array (`beUserUid` key, see `attachBeUserUid()`) and the
 * translators forward it to `trackUsage()` (ADR-052).
 */
final readonly class TranslationService implements TranslationServiceInterface
{
    private const EMPTY_TEXT_ERROR = 'Text cannot be empty';
    private const SUPPORTED_FORMALITIES = ['default', 'formal', 'informal'];
    private const SUPPORTED_DOMAINS = ['general', 'technical', 'medical', 'legal', 'marketing'];

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private TranslatorRegistryInterface $translatorRegistry,
        private LlmConfigurationServiceInterface $configurationService,
        private TranslationPromptBuilder $promptBuilder,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    /**
     * Translate text to target language.
     *
     * @param string      $text           Text to translate
     * @param string      $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): TranslationResult {
        $options ??= new TranslationOptions();

        ['sourceLanguage' => $sourceLanguage, 'prompt' => $prompt] = $this->prepareTranslation(
            $text,
            $targetLanguage,
            $sourceLanguage,
            $options,
            1478981390,
        );

        // Execute translation. REC #2 closure: build typed
        // `ChatMessage` VOs at the construction site rather than
        // relying on LlmServiceManager's back-compat normalisation.
        $messages = [
            ChatMessage::system($prompt['system']),
            ChatMessage::user($prompt['user']),
        ];

        $chatOptions = new ChatOptions(
            temperature: $options->getTemperature() ?? 0.3,
            maxTokens: $options->getMaxTokens() ?? 2000,
            provider: $options->getProvider(),
            model: $options->getModel(),
            beUserUid: $this->resolveBeUserUid($options),
            plannedCost: $options->getPlannedCost(),
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        return new TranslationResult(
            translation: $response->content,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            confidence: $this->calculateConfidence($response->finishReason),
            usage: $response->usage,
        );
    }

    /**
     * Translate text using the persona/tone of a stored LlmConfiguration.
     *
     * Unlike {@see self::translate()} — which supplies its own system
     * message and therefore short-circuits
     * {@see \Netresearch\NrLlm\Service\MessageShaper::applySystemPrompt()},
     * so the configuration's stored `system_prompt` never applies — this
     * method routes through {@see LlmServiceManagerInterface::chatWithConfiguration()}
     * so the configuration drives the provider/model/skills AND its
     * `system_prompt` becomes the system message.
     *
     * The translation task itself (target/source language, formality,
     * glossary, preserve-formatting, "output only the translation") is
     * carried in the USER message rather than a second system message:
     * a system message would re-trigger the short-circuit and clobber the
     * configuration's persona. The configuration's `system_prompt` is the
     * persona/tone layer; this user-message preamble is the task layer.
     *
     * Source-language auto-detection (when `$sourceLanguage` is null) runs
     * through the options' provider, not the configuration — pass an explicit
     * `$sourceLanguage` to avoid that extra call and keep the whole operation
     * on the chosen configuration.
     *
     * @param string      $text           Text to translate
     * @param string      $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     *
     * @throws InvalidArgumentException when input is empty or a language code is invalid
     */
    public function translateForConfiguration(
        string $text,
        string $targetLanguage,
        LlmConfiguration $configuration,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): TranslationResult {
        $options ??= new TranslationOptions();

        // Build the same task/constraints prompt as translate(), but fold the
        // instruction block into the USER message so no system message is sent —
        // that keeps the configuration's stored system_prompt as the system
        // message (see applySystemPrompt short-circuit).
        ['sourceLanguage' => $sourceLanguage, 'prompt' => $prompt] = $this->prepareTranslation(
            $text,
            $targetLanguage,
            $sourceLanguage,
            $options,
            1719410501,
        );

        $messages = [
            ChatMessage::user($prompt['system'] . "\n\n" . $prompt['user']),
        ];

        // Budget metadata for chatWithConfiguration's pipeline (ADR-052):
        // absent keys mean "skip the check", matching the middleware contract.
        $metadata = [];
        $beUserUid = $this->resolveBeUserUid($options);
        if ($beUserUid !== null) {
            $metadata[BudgetMiddleware::METADATA_BE_USER_UID] = $beUserUid;
        }
        if ($options->getPlannedCost() !== null) {
            $metadata[BudgetMiddleware::METADATA_PLANNED_COST] = $options->getPlannedCost();
        }

        // Only forward set generation knobs; unset ones let the configuration's
        // stored defaults apply. Provider is omitted — the configuration owns it.
        $overrides = [];
        if ($options->getTemperature() !== null) {
            $overrides['temperature'] = $options->getTemperature();
        }
        if ($options->getMaxTokens() !== null) {
            $overrides['max_tokens'] = $options->getMaxTokens();
        }
        if ($options->getModel() !== null) {
            $overrides['model'] = $options->getModel();
        }

        $response = $this->llmManager->chatWithConfiguration($messages, $configuration, $metadata, $overrides);

        return new TranslationResult(
            translation: $response->content,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            confidence: $this->calculateConfidence($response->finishReason),
            usage: $response->usage,
        );
    }

    /**
     * Translate multiple texts efficiently.
     *
     * @param array<int, string> $texts          Array of texts to translate
     * @param string             $targetLanguage Target language code
     * @param string|null        $sourceLanguage Source language code (auto-detected if null)
     *
     * @return array<int, TranslationResult> Array of TranslationResult objects
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): array {
        if (empty($texts)) {
            return [];
        }

        $options ??= new TranslationOptions();
        $results = [];

        foreach ($texts as $text) {
            $results[] = $this->translate($text, $targetLanguage, $sourceLanguage, $options);
        }

        return $results;
    }

    /**
     * Detect language of text.
     *
     * @param string $text Text to analyze
     *
     * @return string Language code (ISO 639-1)
     */
    public function detectLanguage(string $text, ?TranslationOptions $options = null): string
    {
        // Standalone detection is a request in its own right, so it counts.
        return $this->runLanguageDetection($text, $options ?? new TranslationOptions(), true);
    }

    /**
     * Shared language-detection implementation.
     *
     * @param bool $countsAsRequest false when detection runs as the internal
     *                              first step of a translate() call — the
     *                              translation records the single request-of-
     *                              record, so the detection sub-call must not
     *                              increment the request counter too (issue #473
     *                              double-count). true for a standalone call.
     *
     * @return string Language code (ISO 639-1)
     */
    private function runLanguageDetection(string $text, TranslationOptions $options, bool $countsAsRequest): string
    {
        $messages = [
            ChatMessage::system('You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.'),
            ChatMessage::user("Detect the language of this text:\n\n" . $text),
        ];

        $chatOptions = (new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
            provider: $options->getProvider(),
            model: $options->getModel(),
            beUserUid: $this->resolveBeUserUid($options),
            plannedCost: $options->getPlannedCost(),
        ))->withSuppressRequestCount(!$countsAsRequest);

        $response = $this->llmManager->chat($messages, $chatOptions);

        $detectedLang = trim(strtolower($response->content));

        // Validate the response is a 2-letter code
        if (!preg_match('/^[a-z]{2}$/', $detectedLang)) {
            // Fallback to 'en' if detection fails
            return 'en';
        }

        return $detectedLang;
    }

    /**
     * Score translation quality.
     *
     * Analyzes translation quality based on accuracy, fluency, and consistency.
     *
     * @param string $sourceText     Original text
     * @param string $translatedText Translated text
     * @param string $targetLanguage Target language code
     *
     * @return float Quality score (0.0-1.0)
     */
    public function scoreTranslationQuality(
        string $sourceText,
        string $translatedText,
        string $targetLanguage,
        ?TranslationOptions $options = null,
    ): float {
        $options ??= new TranslationOptions();
        $messages = [
            ChatMessage::system('You are a translation quality expert. Evaluate the translation quality based on accuracy, fluency, and consistency. Respond with ONLY a number between 0.0 and 1.0 (e.g., "0.85"). No explanation.'),
            ChatMessage::user(sprintf(
                "Source text:\n%s\n\nTranslation to %s:\n%s\n\nQuality score:",
                $sourceText,
                $targetLanguage,
                $translatedText,
            )),
        ];

        $chatOptions = new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
            provider: $options->getProvider(),
            model: $options->getModel(),
            beUserUid: $this->resolveBeUserUid($options),
            plannedCost: $options->getPlannedCost(),
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        $score = (float)trim($response->content);

        // Clamp to 0.0-1.0 range
        return max(0.0, min(1.0, $score));
    }

    /**
     * Translate using specialized translator or LLM.
     *
     * Supports dual-path translation with priority routing:
     * 1. Explicit translator specified in options
     * 2. Translator pinned on a selected LlmConfiguration (options configuration key)
     * 3. Default LLM-based translation
     *
     * @param string      $text           Text to translate
     * @param string      $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     *
     * @return TranslatorResult Translation result with metadata
     */
    public function translateWithTranslator(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): TranslatorResult {
        $options ??= new TranslationOptions();
        $optionsArray = $this->attachBeUserUid($options->toArray(), $options);

        if (empty($text)) {
            throw new InvalidArgumentException(self::EMPTY_TEXT_ERROR, 3459949413);
        }

        $this->validateLanguageCode($targetLanguage);
        if ($sourceLanguage !== null) {
            $this->validateLanguageCode($sourceLanguage);
        }

        // Determine translator to use
        $translator = $this->resolveTranslator($optionsArray);

        // Execute translation via resolved translator
        return $translator->translate($text, $targetLanguage, $sourceLanguage, $optionsArray);
    }

    /**
     * Translate batch using specialized translator or LLM.
     *
     * @param array<int, string> $texts          Texts to translate
     * @param string             $targetLanguage Target language code
     * @param string|null        $sourceLanguage Source language code
     *
     * @return array<int, TranslatorResult> Translation results
     */
    public function translateBatchWithTranslator(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): array {
        if (empty($texts)) {
            return [];
        }

        $options ??= new TranslationOptions();
        $optionsArray = $this->attachBeUserUid($options->toArray(), $options);
        $translator = $this->resolveTranslator($optionsArray);

        return $translator->translateBatch($texts, $targetLanguage, $sourceLanguage, $optionsArray);
    }

    /**
     * Re-attach the usage-attribution uid to a translator options array.
     *
     * `TranslationOptions::toArray()` deliberately excludes the budget
     * fields (pipeline metadata, not wire payload), but the translator
     * path bypasses the middleware pipeline, so without this key the
     * caller-supplied uid would never reach the translators'
     * `trackUsage()` calls (ADR-052). Translators read the key and must
     * not send it to the remote API.
     *
     * @param array<string, mixed> $optionsArray
     *
     * @return array<string, mixed>
     */
    private function attachBeUserUid(array $optionsArray, TranslationOptions $options): array
    {
        $beUserUid = $this->resolveBeUserUid($options);
        if ($beUserUid !== null) {
            $optionsArray['beUserUid'] = $beUserUid;
        }

        // The same reasoning applies to the other two budget fields: the
        // translator path bypasses the middleware pipeline, so a planned cost
        // and a configuration the caller set would never reach the translator's
        // own budget pre-flight (ADR-078). Translators read these keys and must
        // not send them to the remote API.
        $plannedCost = $options->getPlannedCost();
        if ($plannedCost !== null) {
            $optionsArray['plannedCost'] = $plannedCost;
        }

        $configuration = $options->getConfiguration();
        if ($configuration !== null && $configuration !== '') {
            $optionsArray['configuration'] = $configuration;
        }

        return $optionsArray;
    }

    /**
     * Get available translators.
     *
     * @return array<string, array{identifier: string, name: string, available: bool}>
     */
    public function getAvailableTranslators(): array
    {
        return $this->translatorRegistry->getTranslatorInfo();
    }

    /**
     * Check if a specific translator is available.
     */
    public function hasTranslator(string $identifier): bool
    {
        return $this->translatorRegistry->has($identifier);
    }

    /**
     * Get translator by identifier.
     *
     * @throws ServiceUnavailableException
     */
    public function getTranslator(string $identifier): TranslatorInterface
    {
        return $this->translatorRegistry->get($identifier);
    }

    /**
     * Find best translator for a language pair.
     */
    public function findBestTranslator(string $sourceLanguage, string $targetLanguage): ?TranslatorInterface
    {
        return $this->translatorRegistry->findBestTranslator($sourceLanguage, $targetLanguage);
    }

    /**
     * Resolve which translator to use based on options.
     *
     * @param array<string, mixed> $options
     */
    private function resolveTranslator(array $options): TranslatorInterface
    {
        // Priority 1: Explicit translator specified
        $translator = $options['translator'] ?? '';
        if (is_string($translator) && $translator !== '') {
            return $this->translatorRegistry->get($translator);
        }

        // Priority 2: Configuration identifier specified - use the translator
        // pinned on that LlmConfiguration, if any. Reachable via
        // TranslationOptions::withConfiguration() (#430).
        $configurationIdentifier = $options['configuration'] ?? '';
        if (is_string($configurationIdentifier) && $configurationIdentifier !== '') {
            try {
                $configuration = $this->configurationService->getConfiguration($configurationIdentifier);
                if ($configuration->getTranslator() !== '') {
                    return $this->translatorRegistry->get($configuration->getTranslator());
                }
            } catch (ConfigurationNotFoundException) {
                // Fall through to default translator
            }
        }

        // Default: Use LLM-based translator
        return $this->translatorRegistry->get('llm');
    }

    /**
     * Shared preamble for translate() and translateForConfiguration():
     * empty-text guard, language-code validation, source-language
     * detection, option validation, and prompt construction. Only the
     * message assembly and dispatch differ between the two callers.
     *
     * @param int $emptyTextErrorCode caller-specific code for the empty-text guard
     *
     * @throws InvalidArgumentException when input is empty or a language code is invalid
     *
     * @return array{sourceLanguage: string, prompt: array{system: string, user: string}}
     */
    private function prepareTranslation(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage,
        TranslationOptions $options,
        int $emptyTextErrorCode,
    ): array {
        if (empty($text)) {
            throw new InvalidArgumentException(self::EMPTY_TEXT_ERROR, $emptyTextErrorCode);
        }

        $optionsArray = $options->toArray();

        $this->validateLanguageCode($targetLanguage);

        // Auto-detect source language if not provided. This detection is an
        // internal step of the translation, so it records its tokens/cost but
        // does not count as a separate request (issue #473 double-count).
        if ($sourceLanguage === null) {
            $sourceLanguage = $this->runLanguageDetection($text, $options, false);
        } else {
            $this->validateLanguageCode($sourceLanguage);
        }

        // Validate options
        $this->validateOptions($optionsArray);

        $prompt = $this->promptBuilder->build(
            $text,
            $sourceLanguage,
            $targetLanguage,
            $optionsArray,
        );

        return [
            'sourceLanguage' => $sourceLanguage,
            'prompt' => $prompt,
        ];
    }

    /**
     * Calculate confidence score from finish reason.
     */
    private function calculateConfidence(string $finishReason): float
    {
        return match ($finishReason) {
            'stop' => 0.9,
            'length' => 0.6,
            default => 0.5,
        };
    }

    /**
     * Validate language code format.
     *
     * @throws InvalidArgumentException
     */
    private function validateLanguageCode(string $languageCode): void
    {
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $languageCode)) {
            throw new InvalidArgumentException(
                'Invalid language code format. Expected ISO 639-1 (e.g., "en", "de-DE")',
                8727807751,
            );
        }
    }

    /**
     * Validate translation options.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        if (isset($options['formality']) && !in_array($options['formality'], self::SUPPORTED_FORMALITIES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid formality. Supported: %s',
                    implode(', ', self::SUPPORTED_FORMALITIES),
                ),
                6448506079,
            );
        }

        if (isset($options['domain']) && !in_array($options['domain'], self::SUPPORTED_DOMAINS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid domain. Supported: %s',
                    implode(', ', self::SUPPORTED_DOMAINS),
                ),
                3885497401,
            );
        }

        if (isset($options['glossary']) && !is_array($options['glossary'])) {
            throw new InvalidArgumentException('Glossary must be an associative array', 8571915742);
        }
    }

    /**
     * Resolve the backend user uid for budget pre-flight (REC #4
     * slice 15b). Honours an explicit caller-supplied uid (including
     * `0` for "skip the check") and falls back to the resolver only
     * when the option was left null.
     *
     * Different shape from the other feature services: this service
     * builds *new* `ChatOptions` from `TranslationOptions` rather
     * than mutating an existing options object, so it returns a raw
     * `?int` that each construction site forwards to the new
     * `ChatOptions` constructor — `AutoPopulatesBeUserUidTrait` does
     * not fit (it expects an options object the caller can mutate).
     */
    private function resolveBeUserUid(TranslationOptions $options): ?int
    {
        return $options->getBeUserUid() ?? $this->beUserContextResolver?->resolveBeUserUid();
    }
}
