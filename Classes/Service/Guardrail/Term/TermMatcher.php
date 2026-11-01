<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Normalizer;

use function array_values;
use function class_exists;
use function html_entity_decode;
use function implode;
use function preg_last_error_msg;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function str_ends_with;
use function substr;
use function trim;

/**
 * Decides whether a term occurs in a text (ADR-238).
 *
 * The whole matching contract, and the page an editor reads says the same:
 *
 * - Case is ignored, by Unicode case folding of the regular expression engine.
 *   `ß` and `ss` stay different letters.
 * - A term matches whole words. Neither side may touch a letter, a digit or an
 *   underscore, so `Kunde` does not match `Kundenkonto`.
 * - A space in a term matches any run of white space, line breaks and
 *   non-breaking spaces included.
 * - There is no inflection and no stemming. `Rabatt` does not match `Rabatte`.
 *   A trailing `*` is the one way to reach further: it takes any letters, digits
 *   and underscores after the term, so `Rabatt*` also matches `Rabatte`,
 *   `Rabattaktion` and `Rabatt2026`. It matches nothing before the term.
 * - Markup does not hide a term: the text is searched as delivered and once
 *   more with tags removed, entities decoded and invisible format characters
 *   dropped, so `Ra<b>batt</b>` and `Pr&uuml;fung` are found.
 *
 * Synonyms, misspellings and paraphrases are not found. This is a word list,
 * not a reader.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermMatcher
{
    /**
     * The two forms of a text that are searched.
     *
     * @return list<string>
     */
    public function searchForms(string $text): array
    {
        $normalized = $this->normalize($text);
        $stripped   = $this->normalize(html_entity_decode($this->withoutTags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $stripped   = preg_replace('/\p{Cf}/u', '', $stripped) ?? $stripped;

        return $stripped === $normalized ? [$normalized] : [$normalized, $stripped];
    }

    /**
     * @param list<string> $forms from {@see self::searchForms()}
     *
     * @throws TermCheckFailedException when the pattern engine gives up, which
     *                                  must not read as "the term is absent"
     */
    public function occursIn(string $term, array $forms): bool
    {
        $pattern = $this->patternFor($term);
        foreach ($forms as $form) {
            $found = preg_match($pattern, $form);
            if ($found === false) {
                throw new TermCheckFailedException('Term pattern failed: ' . preg_last_error_msg(), 1793800001);
            }

            if ($found === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Words of a text, markup not counted.
     */
    public function wordCount(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $this->withoutTags($text, ' '));
    }

    /**
     * Real tags only. strip_tags() also eats everything after a lone `<`, so
     * "Lieferzeit <5 Tage" would lose the rest of the text.
     */
    private function withoutTags(string $text, string $replacement = ''): string
    {
        return preg_replace('/<\/?[a-z][^<>]*>/i', $replacement, $text) ?? $text;
    }

    private function patternFor(string $term): string
    {
        $normalized = $this->normalize($term);
        $prefix     = str_ends_with($normalized, '*');
        $body       = $prefix ? trim(substr($normalized, 0, -1)) : $normalized;

        $words  = preg_split('/[\s\p{Z}]+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $quoted = implode('[\s\p{Z}]+', array_values(array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            $words,
        )));

        // The boundary is spelled out rather than \b: \b without UCP works on
        // ASCII word characters, and a term that starts or ends with a
        // non-word character (`C++`, `.NET`) has no \b on that side at all.
        return '/(?<![\p{L}\p{M}\p{N}_])' . $quoted . ($prefix ? '[\p{L}\p{M}\p{N}_]*' : '(?![\p{L}\p{M}\p{N}_])') . '/iu';
    }

    /**
     * Canonical composition where the intl extension provides it, so a
     * decomposed `ü` from a model equals the composed one in the list.
     */
    private function normalize(string $text): string
    {
        if (!class_exists(Normalizer::class)) {
            return $text;
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $text;
    }
}
