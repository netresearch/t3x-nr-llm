<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
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
 * characters with the caller's extension and never counts as a request.
 *
 * Whether the chat call counts depends on the first pass. DeepL writes a
 * counting request row for the same backend user before the revision runs;
 * then the chat call is marked as already counted, so translation and
 * revision are one request, also in the user's request limits (#473). After
 * any other translator nothing guarantees such a row, so the chat call is an
 * ordinary request with an ordinary pre-flight: translation and revision
 * never consume zero requests.
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

    /**
     * An HTML or XML tag or an HTML comment, compared normalised (see
     * {@see self::normaliseTag()}) with its attributes and in document order,
     * so crossed nesting, swapped link targets and a dropped comment are
     * caught. Which words a tag encloses is not compared.
     */
    private const TAG_PATTERN = '/<!--.*?-->|<\/?[A-Za-z][^<>]*>/s';

    /** One attribute: a name and an optional double-, single- or unquoted value. */
    private const ATTRIBUTE_PATTERN = '/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/';

    /**
     * A placeholder: `{name}` (also inside `{{name}}`), or a printf conversion
     * such as `%s`, `%d`, `%1$s`, `%.2f`. No space flag, so "20 % sale" is no
     * placeholder. Compared as a multiset: word order may change. Not covered:
     * ICU plural/select blocks, `{{ name }}` with spaces, `%%` and HTML
     * entities.
     */
    private const PLACEHOLDER_PATTERN = '/\{[^{}\s]+\}|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

    /** A Markdown code fence at the start of a text. */
    private const FENCE_PATTERN = '/^\s*(?:```|~~~)/';

    /** A model's introduction to its answer, at the start of a text. */
    private const PREAMBLE_PATTERN = '/^\s*(?:here(?:\'s| is| are)\b|hier (?:ist|sind)\b|below (?:is|are)\b|sure[,!.]|certainly[,!.]|gerne[,!.])/iu';

    /** A first line that ends in a colon and is followed by more text. */
    private const INTRODUCING_LINE_PATTERN = '/^[^\n]*:[ \t]*\r?\n/u';

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private UsageTrackerServiceInterface $usageTracker,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string                $sourceText            the text the translator received
     * @param array<string, string> $glossary              source term => target term; the
     *                                                     target terms must survive
     * @param bool                  $requestAlreadyCounted the first pass wrote a counting
     *                                                     request row for this user (DeepL)
     */
    public function polish(
        TranslatorResult $draft,
        string $sourceText,
        TranslationOptions $options,
        array $glossary,
        ?int $beUserUid,
        bool $requestAlreadyCounted,
    ): TranslatorResult {
        if (trim($draft->translatedText) === '' || ($draft->metadata['truncated'] ?? false) === true) {
            return self::outcome($draft, false, 'incomplete_translation');
        }

        // Nothing a revision does may fail the translation: every exception,
        // also one after the answer arrived, falls back to the first one.
        try {
            return $this->revise($draft, $sourceText, $options, $glossary, $beUserUid, $requestAlreadyCounted);
        } catch (Throwable $e) {
            $budgetRefusal = self::isBudgetRefusal($e);
            $reason        = $budgetRefusal ? 'budget_refused' : 'failed';
            $context       = [
                'reason'    => $reason,
                'exception' => $e,
                'message'   => $this->sanitizeErrorMessage($e->getMessage()),
            ];
            // A refused budget and a provider failure are expected outcomes;
            // anything else is a defect in the revision and is logged as one.
            if ($budgetRefusal || $e instanceof ProviderException) {
                $this->logger->warning('Translation polish skipped, the first translation is returned.', $context);
            } else {
                $this->logger->error('Translation polish failed unexpectedly, the first translation is returned.', $context);
            }

            return self::outcome($draft, false, $reason);
        }
    }

    /**
     * @param array<string, string> $glossary
     */
    private function revise(
        TranslatorResult $draft,
        string $sourceText,
        TranslationOptions $options,
        array $glossary,
        ?int $beUserUid,
        bool $requestAlreadyCounted,
    ): TranslatorResult {
        $provider  = $options->getProvider() !== null && $options->getProvider() !== '' ? $options->getProvider() : null;
        $extension = $options->getCallerSourceExtension() ?? '';

        // After DeepL its row is the request of record and was written before
        // this call: not counted again, neither in the usage rows nor in the
        // user's request limits. After any other translator the call is an
        // ordinary request.
        $chatOptions = (new ChatOptions(
            temperature: $options->getTemperature() ?? 0.2,
            maxTokens: $options->getMaxTokens() ?? ($provider === null ? $this->outputBudgetFor($draft->translatedText) : self::MIN_OUTPUT_TOKENS),
            provider: $provider,
            model: $options->getModel(),
            beUserUid: $beUserUid,
            plannedCost: $options->getPlannedCost(),
        ))->withRequestAlreadyCounted($requestAlreadyCounted);
        if ($extension !== '') {
            $chatOptions = $chatOptions->withCallerSource($extension, $options->getCallerSourceOperation() ?? '');
        }

        $response = $this->llmManager->chat($this->messages($draft, $sourceText, $options, $glossary), $chatOptions);

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

        if (preg_match(self::FENCE_PATTERN, $answer) === 1 && preg_match(self::FENCE_PATTERN, $draft) !== 1) {
            return 'code_fence';
        }

        if (preg_match(self::PREAMBLE_PATTERN, $answer) === 1 && preg_match(self::PREAMBLE_PATTERN, $draft) !== 1) {
            return 'preamble';
        }

        if (preg_match(self::INTRODUCING_LINE_PATTERN, $answer) === 1 && preg_match(self::INTRODUCING_LINE_PATTERN, $draft) !== 1) {
            return 'preamble';
        }

        // A note before or after the text adds a line; a revision does not.
        if (self::nonEmptyLines($answer) > self::nonEmptyLines($draft)) {
            return 'added_lines';
        }

        if (self::tags($draft) !== self::tags($answer)) {
            return 'markup_changed';
        }

        if (self::occurrences(self::PLACEHOLDER_PATTERN, $draft, true) !== self::occurrences(self::PLACEHOLDER_PATTERN, $answer, true)) {
            return 'placeholders_changed';
        }

        // Case-sensitive substring counting: a term also counts inside a
        // longer word ("Kundenportal" in "Kundenportale"), and "kundenportal"
        // does not count for "Kundenportal".

        foreach ($glossary as $target) {
            if ($target !== '' && substr_count($answer, $target) < substr_count($draft, $target)) {
                return 'glossary_term_changed';
            }
        }

        return null;
    }

    /**
     * Every match of the pattern, in document order, or sorted — a multiset,
     * so a moved match is accepted and a dropped, added or altered one is not.
     *
     * @return list<string>
     */
    private static function occurrences(string $pattern, string $text, bool $sorted): array
    {
        preg_match_all($pattern, $text, $matches);
        $found = $matches[0];
        if ($sorted) {
            sort($found);
        }

        return $found;
    }

    /**
     * The tags and comments of a text in document order, normalised so that
     * `<br>` and `<br />`, `<B>` and `<b>`, and single and double quotes
     * around an attribute value count as the same tag.
     *
     * @return list<string>
     */
    private static function tags(string $text): array
    {
        return array_map(self::normaliseTag(...), self::occurrences(self::TAG_PATTERN, $text, false));
    }

    private static function normaliseTag(string $tag): string
    {
        if (str_starts_with($tag, '<!--')) {
            return (string) preg_replace('/\s+/u', ' ', $tag);
        }

        // A trailing `/` lands in the attribute part, whose pattern skips it.
        if (preg_match('/^<(\/?)([A-Za-z][^\s\/>]*)(.*)>$/s', $tag, $parts) !== 1) {
            return $tag;
        }

        $attributes = [];
        preg_match_all(self::ATTRIBUTE_PATTERN, $parts[3], $matches, PREG_SET_ORDER);
        foreach ($matches as $attribute) {
            $value        = ($attribute[2] ?? '') . ($attribute[3] ?? '') . ($attribute[4] ?? '');
            $attributes[] = strtolower($attribute[1]) . (isset($attribute[2]) ? '="' . $value . '"' : '');
        }

        return '<' . $parts[1] . strtolower($parts[2]) . ($attributes === [] ? '' : ' ' . implode(' ', $attributes)) . '>';
    }

    private static function nonEmptyLines(string $text): int
    {
        return count(array_filter(preg_split('/\R/u', $text) ?: [], static fn (string $line): bool => trim($line) !== ''));
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
