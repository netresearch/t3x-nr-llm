<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revises a translation that a translator other than the LLM one produced
 * (DeepL), for fluency, terminology and tone (ADR-233).
 *
 * The revision is one chat call through {@see LlmServiceManagerInterface::chat()},
 * so the budget pre-flight and the token/cost row of the provider pipeline
 * apply. The `translation_polish` usage row written here records the revised
 * characters with the caller's extension. Neither row counts as a request:
 * the translator's own row is the single request of record of the
 * translation, as for the language detection inside a translation (#473).
 *
 * A revision never fails the translation. A failed or refused call, an empty
 * or cut-off answer, and an answer whose tags, placeholders or glossary terms
 * differ from the first translation return the first translation unchanged,
 * with `metadata['polish']['reason']` saying why.
 *
 * @internal
 */
final readonly class TranslationPolisher
{
    use ErrorMessageSanitizerTrait;

    /** The service type of the usage row a revision writes. */
    public const OPERATION = 'translation_polish';

    /** The output budget of a short text, as in LlmTranslator. */
    private const MIN_OUTPUT_TOKENS = 2000;

    /** The largest output budget asked for without an explicit `max_tokens`. */
    private const MAX_OUTPUT_TOKENS = 16000;

    /** An HTML or XML tag, compared with its attributes. */
    private const TAG_PATTERN = '/<\/?[A-Za-z][^<>]*>/';

    /**
     * A placeholder: `{name}` (also inside `{{name}}`), or a printf conversion
     * such as `%s`, `%d`, `%1$s`, `%.2f`. No space flag, so "20 % sale" is no
     * placeholder.
     */
    private const PLACEHOLDER_PATTERN = '/\{[^{}\s]+\}|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private UsageTrackerServiceInterface $usageTracker,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string                $sourceText the text the translator received
     * @param array<string, string> $glossary   source term => target term; the
     *                                          target terms must survive
     */
    public function polish(
        TranslatorResult $draft,
        string $sourceText,
        TranslationOptions $options,
        array $glossary,
        ?int $beUserUid,
    ): TranslatorResult {
        if (trim($draft->translatedText) === '' || ($draft->metadata['truncated'] ?? false) === true) {
            return self::outcome($draft, false, 'incomplete_translation');
        }

        $provider  = $options->getProvider() !== null && $options->getProvider() !== '' ? $options->getProvider() : null;
        $extension = $options->getCallerSourceExtension() ?? '';

        $chatOptions = (new ChatOptions(
            temperature: $options->getTemperature() ?? 0.2,
            maxTokens: $options->getMaxTokens() ?? ($provider === null ? $this->outputBudgetFor($draft->translatedText) : self::MIN_OUTPUT_TOKENS),
            provider: $provider,
            model: $options->getModel(),
            beUserUid: $beUserUid,
            plannedCost: $options->getPlannedCost(),
        ))->withSuppressRequestCount(true);
        if ($extension !== '') {
            $chatOptions = $chatOptions->withCallerSource($extension, $options->getCallerSourceOperation() ?? '');
        }

        try {
            $response = $this->llmManager->chat($this->messages($draft, $sourceText, $options, $glossary), $chatOptions);
        } catch (Throwable $e) {
            $reason = self::isBudgetRefusal($e) ? 'budget_refused' : 'failed';
            $this->logger->warning('Translation polish skipped, the first translation is returned.', [
                'reason'    => $reason,
                'exception' => $e::class,
                'message'   => $this->sanitizeErrorMessage($e->getMessage()),
            ]);

            return self::outcome($draft, false, $reason);
        }

        $this->recordUsage($draft, $provider, $response->model, $beUserUid, $extension);

        $reason = self::rejectionReason($draft->translatedText, $response->content, $response->finishReason, $glossary);
        if ($reason !== null) {
            $this->logger->info('Translation polish rejected, the first translation is returned.', [
                'reason' => $reason,
                'model'  => $response->model,
            ]);

            return self::outcome($draft, false, $reason, $response->model);
        }

        // The answer is trimmed; the whitespace around the first translation
        // is kept, because the caller may rely on it.
        preg_match('/^\s*/u', $draft->translatedText, $leading);
        preg_match('/\s*$/u', $draft->translatedText, $trailing);
        $polished = new TranslatorResult(
            translatedText: ($leading[0] ?? '') . trim($response->content) . ($trailing[0] ?? ''),
            sourceLanguage: $draft->sourceLanguage,
            targetLanguage: $draft->targetLanguage,
            translator: $draft->translator,
            confidence: $draft->confidence,
            alternatives: $draft->alternatives,
            charactersUsed: $draft->charactersUsed,
            metadata: $draft->metadata,
        );

        return self::outcome($polished, true, null, $response->model);
    }

    /**
     * The result with `metadata['polish']` set: whether the revision was
     * applied, why not, and the model that answered.
     */
    public static function outcome(TranslatorResult $result, bool $applied, ?string $reason, ?string $model = null): TranslatorResult
    {
        $polish = ['applied' => $applied];
        if ($reason !== null) {
            $polish['reason'] = $reason;
        }

        if ($model !== null && $model !== '') {
            $polish['model'] = $model;
        }

        return new TranslatorResult(
            translatedText: $result->translatedText,
            sourceLanguage: $result->sourceLanguage,
            targetLanguage: $result->targetLanguage,
            translator: $result->translator,
            confidence: $result->confidence,
            alternatives: $result->alternatives,
            charactersUsed: $result->charactersUsed,
            metadata: ['polish' => $polish] + ($result->metadata ?? []),
        );
    }

    /**
     * Why an answer cannot replace the first translation, or null when it can.
     *
     * @param array<string, string> $glossary
     */
    private static function rejectionReason(string $draft, string $answer, string $finishReason, array $glossary): ?string
    {
        if (trim($answer) === '') {
            return 'empty';
        }

        if ($finishReason === 'length') {
            return 'truncated';
        }

        if (self::occurrences(self::TAG_PATTERN, $draft) !== self::occurrences(self::TAG_PATTERN, $answer)) {
            return 'markup_changed';
        }

        if (self::occurrences(self::PLACEHOLDER_PATTERN, $draft) !== self::occurrences(self::PLACEHOLDER_PATTERN, $answer)) {
            return 'placeholders_changed';
        }

        foreach ($glossary as $target) {
            if ($target !== '' && substr_count($answer, $target) < substr_count($draft, $target)) {
                return 'glossary_term_changed';
            }
        }

        return null;
    }

    /**
     * Every match of the pattern, sorted: a multiset, so a moved tag is
     * accepted and a dropped, added or altered one is not.
     *
     * @return list<string>
     */
    private static function occurrences(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    private static function isBudgetRefusal(Throwable $e): bool
    {
        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof BudgetExceededException) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $glossary
     *
     * @return list<ChatMessage>
     */
    private function messages(TranslatorResult $draft, string $sourceText, TranslationOptions $options, array $glossary): array
    {
        $system = sprintf(
            "You are a professional editor of texts in the language with the code \"%s\". You receive a source text in the language with the code \"%s\" and its machine translation. Revise the translation for fluency, consistent terminology and a natural tone.\n\n"
            . "Rules:\n"
            . "- Keep the meaning of the source text exactly. Do not add, drop or explain anything.\n"
            . "- Keep every HTML or XML tag with its attributes and every placeholder, such as {name}, %%s or %%1\$s, exactly as it appears in the translation.\n",
            $draft->targetLanguage,
            $draft->sourceLanguage,
        );

        $formality = $options->getFormality();
        if ($formality !== null && $formality !== 'default') {
            $system .= sprintf("- Keep the %s tone and form of address of the translation.\n", $formality);
        }

        $domain = $options->getDomain();
        if ($domain !== null && $domain !== 'general') {
            $system .= sprintf("- The text belongs to the %s domain.\n", $domain);
        }

        $terms = array_values(array_filter($glossary, static fn (string $term): bool => $term !== ''));
        if ($terms !== []) {
            $system .= "- Keep these terms exactly as written wherever they appear:\n";
            foreach ($terms as $term) {
                $system .= '  - ' . $term . "\n";
            }
        }

        $instruction = $options->getPolishInstruction();
        if ($instruction !== null) {
            $system .= '- Additional instruction: ' . $instruction . "\n";
        }

        $system .= "\nReturn ONLY the revised translation, without quotes, notes or code fences.";

        $user    = '';
        $context = $options->getContext();
        if ($context !== null && $context !== '') {
            $user .= "Context (for reference only):\n" . $context . "\n\n";
        }

        $user .= "Source text:\n" . $sourceText . "\n\nTranslation to revise:\n" . $draft->translatedText;

        return [ChatMessage::system($system), ChatMessage::user($user)];
    }

    /**
     * The usage row of a revision; not a request, the translator's row is.
     * Bookkeeping only: a tracker failure is logged and leaves the revision in
     * place.
     */
    private function recordUsage(TranslatorResult $draft, ?string $provider, string $model, ?int $beUserUid, string $extension): void
    {
        try {
            $this->usageTracker->trackUsage(
                self::OPERATION,
                'llm:' . ($provider ?? 'default'),
                ['characters' => mb_strlen($draft->translatedText)],
                modelId: $model,
                beUserUid: $beUserUid,
                countsAsRequest: false,
                sourceExtension: $extension,
            );
        } catch (Throwable $e) {
            $this->logger->warning('Usage tracking of a translation polish failed; the revision is unaffected.', [
                'exception' => $e::class,
                'message'   => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
        }
    }

    /**
     * As {@see \Netresearch\NrLlm\Specialized\Translation\LlmTranslator}: 2000 to 16000, one token per byte.
     */
    private function outputBudgetFor(string $text): int
    {
        return min(self::MAX_OUTPUT_TOKENS, max(self::MIN_OUTPUT_TOKENS, strlen($text)));
    }
}
