<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use Closure;
use DeepL\Language;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;

use function array_key_exists;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function sprintf;
use function strtolower;
use function trim;

use const PHP_SAPI;

/**
 * Translates for EXT:autotranslate through nr-llm (ADR-289).
 *
 * autotranslate talks to a `\DeepL\Translator`, so this class is one: every
 * type hint in autotranslate's translation flow keeps holding. It is used when
 * autotranslate's `translatorClass` setting names it, and it overrides each
 * method autotranslate calls on a translator. Its parent is never asked to
 * reach DeepL: it is built with a placeholder key and a server address under
 * the reserved `.invalid` domain, so a method this class does not override
 * fails on the spot instead of sending a text to DeepL.
 *
 * The texts go to {@see TranslationServiceInterface::translateBatchWithTranslator()},
 * to the translator named in `autotranslate.translator`, or to the language
 * model when that is empty. Rich text keeps its markup: DeepL's `tag_handling`
 * becomes nr-llm's tag handling. On the command line the call reads the
 * provider key as the worker identity of ADR-234.
 *
 * @api The class name is a configuration value: autotranslate's `translatorClass` names it.
 */
final class AutotranslateTranslator extends Translator // @phpstan-ignore class.extendsDeprecatedClass (autotranslate type-hints \DeepL\Translator, and DeepLClient needs deepl-php 1.11 where autotranslate allows 1.5)
{
    private const PLACEHOLDER_AUTH_KEY = 'nr-llm-routed';

    private const UNREACHABLE_SERVER = 'https://deepl.nr-llm.invalid';

    /**
     * DeepL's language codes, which autotranslate stores in the site
     * configuration. The language model is not limited to them; the lists only
     * fill autotranslate's selection fields.
     */
    private const SOURCE_LANGUAGES = [
        'BG' => 'Bulgarian', 'CS' => 'Czech', 'DA' => 'Danish', 'DE' => 'German',
        'EL' => 'Greek', 'EN' => 'English', 'ES' => 'Spanish', 'ET' => 'Estonian',
        'FI' => 'Finnish', 'FR' => 'French', 'HU' => 'Hungarian', 'ID' => 'Indonesian',
        'IT' => 'Italian', 'JA' => 'Japanese', 'KO' => 'Korean', 'LT' => 'Lithuanian',
        'LV' => 'Latvian', 'NB' => 'Norwegian (Bokmål)', 'NL' => 'Dutch', 'PL' => 'Polish',
        'PT' => 'Portuguese', 'RO' => 'Romanian', 'RU' => 'Russian', 'SK' => 'Slovak',
        'SL' => 'Slovenian', 'SV' => 'Swedish', 'TR' => 'Turkish', 'UK' => 'Ukrainian',
        'ZH' => 'Chinese',
    ];

    private const TARGET_LANGUAGES = [
        'BG'    => 'Bulgarian', 'CS' => 'Czech', 'DA' => 'Danish', 'DE' => 'German',
        'EL'    => 'Greek', 'EN-GB' => 'English (British)', 'EN-US' => 'English (American)',
        'ES'    => 'Spanish', 'ET' => 'Estonian', 'FI' => 'Finnish', 'FR' => 'French',
        'HU'    => 'Hungarian', 'ID' => 'Indonesian', 'IT' => 'Italian', 'JA' => 'Japanese',
        'KO'    => 'Korean', 'LT' => 'Lithuanian', 'LV' => 'Latvian', 'NB' => 'Norwegian (Bokmål)',
        'NL'    => 'Dutch', 'PL' => 'Polish', 'PT-BR' => 'Portuguese (Brazilian)',
        'PT-PT' => 'Portuguese (European)', 'RO' => 'Romanian', 'RU' => 'Russian',
        'SK'    => 'Slovak', 'SL' => 'Slovenian', 'SV' => 'Swedish', 'TR' => 'Turkish',
        'UK'    => 'Ukrainian', 'ZH' => 'Chinese',
    ];

    private const TAG_HANDLINGS = ['html', 'xml'];

    public function __construct(
        private readonly TranslationServiceInterface $translationService,
        private readonly AutotranslateSettings $settings,
        private readonly TechnicalActorContextInterface $technicalActor,
        private readonly bool $commandLine = PHP_SAPI === 'cli',
    ) {
        parent::__construct( // @phpstan-ignore method.deprecatedClass (see the class declaration)
            self::PLACEHOLDER_AUTH_KEY, [
                TranslatorOptions::SERVER_URL  => self::UNREACHABLE_SERVER,
                TranslatorOptions::MAX_RETRIES => 0,
            ]);
    }

    /**
     * Same signature as `\DeepL\Translator::translateText()`.
     *
     * A blank text is answered with itself and not sent. The others go to
     * nr-llm in one batch, in their order.
     *
     * @param string|string[]      $texts
     * @param array<string, mixed> $options DeepL options; only `tag_handling` is used
     *
     * @return TextResult|TextResult[]
     *
     * @phpstan-return ($texts is array ? TextResult[] : TextResult)
     */
    public function translateText($texts, ?string $sourceLang, string $targetLang, array $options = [])
    {
        $list           = is_array($texts) ? array_values($texts) : [$texts];
        $sourceLanguage = $this->baseLanguage($sourceLang);
        $targetLanguage = $this->targetLanguage($targetLang);

        $pending = [];
        foreach ($list as $index => $text) {
            if (trim((string) $text) !== '') {
                $pending[$index] = (string) $text;
            }
        }

        $translated = $pending === [] ? [] : $this->translate($pending, $targetLanguage, $sourceLanguage, $options);

        $results = [];
        foreach ($list as $index => $text) {
            $results[] = array_key_exists($index, $translated)
                ? $translated[$index]
                : new TextResult((string) $text, $sourceLanguage ?? '', 0);
        }

        return is_array($texts) ? $results : $results[0];
    }

    /**
     * autotranslate checks a translator's quota. nr-llm counts its own usage
     * and budgets, so this reports no limit.
     */
    public function getUsage(): Usage
    {
        return new Usage('{}');
    }

    /**
     * @return Language[]
     */
    public function getSourceLanguages(): array
    {
        return $this->languages(self::SOURCE_LANGUAGES, null);
    }

    /**
     * @return Language[]
     */
    public function getTargetLanguages(): array
    {
        return $this->languages(self::TARGET_LANGUAGES, false);
    }

    /**
     * DeepL glossaries do not apply to nr-llm, so autotranslate finds none.
     *
     * @return array<never>
     */
    public function listGlossaries(): array
    {
        return [];
    }

    /**
     * @param array<int, string>   $pending texts by their position in the request
     * @param array<string, mixed> $options
     *
     * @return array<int, TextResult> by the same positions
     */
    private function translate(array $pending, string $targetLanguage, ?string $sourceLanguage, array $options): array
    {
        $translationOptions = new TranslationOptions();
        $tagHandling        = $options[TranslateTextOptions::TAG_HANDLING] ?? null;
        if (is_string($tagHandling) && in_array($tagHandling, self::TAG_HANDLINGS, true)) {
            $translationOptions = $translationOptions->withTagHandling($tagHandling);
        }

        $translator = $this->settings->translator();
        if ($translator !== '') {
            $translationOptions = $translationOptions->withTranslator($translator);
        }

        $texts   = array_values($pending);
        $answers = $this->asWorker(
            fn (): array => $this->translationService->translateBatchWithTranslator(
                $texts,
                $targetLanguage,
                $sourceLanguage,
                $translationOptions,
            ),
        );

        // A missing answer would move every later text into the wrong field.
        if (count($answers) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'nr-llm answered %d of %d texts for autotranslate; nothing is stored.',
                count($answers),
                count($texts),
            ), 1798100001);
        }

        $answers  = array_values($answers);
        $results  = [];
        $position = 0;
        foreach ($pending as $index => $text) {
            $results[$index] = $this->checkedResult($answers[$position], $text, $sourceLanguage);
            ++$position;
        }

        return $results;
    }

    /**
     * A console command boots a backend user that is not logged in, and nr_vault
     * gives such a process no provider key unless `allowCliAccess` is on. On
     * the command line the call therefore reads the key as the worker identity
     * of ADR-234, as nr-llm's own queue worker does. A web request keeps its
     * logged-in user.
     *
     * @param Closure(): array<int, TranslatorResult> $work
     *
     * @return array<int, TranslatorResult>
     */
    private function asWorker(Closure $work): array
    {
        $uid = $this->settings->workerBeUserUid();
        if ($uid < 1 || !$this->commandLine) {
            return $work();
        }

        return $this->technicalActor->runAs($uid, $work);
    }

    /**
     * autotranslate stores what it gets with plain SQL, so an answer that is
     * no translation of the whole text fails the call: a blank answer, and one
     * the model cut off at its output limit (ADR-209).
     */
    private function checkedResult(TranslatorResult $answer, string $text, ?string $sourceLanguage): TextResult
    {
        if (trim($answer->translatedText) === '') {
            throw new RuntimeException('nr-llm returned a blank translation for autotranslate; nothing is stored.', 1798100002);
        }

        if (($answer->metadata['truncated'] ?? false) === true) {
            throw new RuntimeException('nr-llm returned a translation cut off at its output limit for autotranslate; nothing is stored.', 1798100003);
        }

        return new TextResult(
            $answer->translatedText,
            $answer->sourceLanguage !== '' ? $answer->sourceLanguage : ($sourceLanguage ?? ''),
            $answer->charactersUsed ?? mb_strlen($text),
        );
    }

    /**
     * A DeepL source code never carries a region; nr-llm takes the ISO 639-1
     * code.
     */
    private function baseLanguage(?string $language): ?string
    {
        $language = trim((string) $language);
        if ($language === '') {
            return null;
        }

        return strtolower(explode('-', $language)[0]);
    }

    /**
     * A DeepL target code keeps its region in nr-llm's form (EN-GB becomes
     * en-GB), which nr-llm's DeepL translator needs for English and
     * Portuguese.
     */
    private function targetLanguage(string $language): string
    {
        $parts = explode('-', trim($language), 2);
        $code  = strtolower($parts[0]);

        return isset($parts[1]) ? $code . '-' . strtoupper($parts[1]) : $code;
    }

    /**
     * @param array<string, string> $catalogue
     *
     * @return Language[]
     */
    private function languages(array $catalogue, ?bool $supportsFormality): array
    {
        $languages = [];
        foreach ($catalogue as $code => $name) {
            $languages[] = new Language($name, $code, $supportsFormality);
        }

        return $languages;
    }
}
