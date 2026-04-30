<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;

/**
 * Public surface of the high-level translation service.
 *
 * Consumers (controllers, scheduled jobs, tests, downstream extensions)
 * should depend on this interface rather than the concrete
 * `TranslationService` so the implementation can be substituted without
 * inheritance.
 *
 * The service exposes two execution paths:
 * - LLM-based: `translate`, `translateBatch`, `detectLanguage`,
 *   `scoreTranslationQuality` — go through `LlmServiceManager` and the
 *   middleware pipeline (BudgetMiddleware aware).
 * - Specialized translators: `translateWithTranslator`,
 *   `translateBatchWithTranslator`, plus registry queries — bypass the
 *   LLM pipeline and dispatch to translators registered via
 *   `#[AsTranslator]` (DeepL, etc.).
 */
interface TranslationServiceInterface
{
    /**
     * Translate text using the LLM-based path.
     *
     * @param string|null $sourceLanguage ISO 639-1 code, or null for auto-detection
     *
     * @throws InvalidArgumentException
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): TranslationResult;

    /**
     * Translate multiple texts using the LLM-based path. Empty input returns `[]`.
     *
     * @param array<int, string> $texts
     *
     * @return array<int, TranslationResult>
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): array;

    /**
     * Detect the language of the given text. Returns ISO 639-1 code (defaults to "en" on failure).
     */
    public function detectLanguage(string $text, ?TranslationOptions $options = null): string;

    /**
     * Score a translation's quality on `[0.0, 1.0]` (accuracy + fluency + consistency).
     */
    public function scoreTranslationQuality(
        string $sourceText,
        string $translatedText,
        string $targetLanguage,
        ?TranslationOptions $options = null,
    ): float;

    /**
     * Translate via a specialized translator (DeepL etc.) resolved from options/preset/default.
     *
     * @throws InvalidArgumentException when input is empty or language code invalid
     */
    public function translateWithTranslator(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): TranslatorResult;

    /**
     * Batch variant of `translateWithTranslator`. Empty input returns `[]`.
     *
     * @param array<int, string> $texts
     *
     * @return array<int, TranslatorResult>
     */
    public function translateBatchWithTranslator(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null,
    ): array;

    /**
     * @return array<string, array{identifier: string, name: string, available: bool}>
     */
    public function getAvailableTranslators(): array;

    /**
     * Whether a translator is registered under the given identifier.
     */
    public function hasTranslator(string $identifier): bool;

    /**
     * Look up a registered translator by identifier.
     *
     * @throws ServiceUnavailableException when no translator is registered under `$identifier`
     */
    public function getTranslator(string $identifier): TranslatorInterface;

    /**
     * Pick the highest-priority translator that supports the given language pair, or `null` when none match.
     */
    public function findBestTranslator(string $sourceLanguage, string $targetLanguage): ?TranslatorInterface;
}
