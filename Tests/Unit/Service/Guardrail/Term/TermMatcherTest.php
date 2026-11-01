<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\ValueObject\TermLines;
use Netresearch\NrLlm\Service\Guardrail\Term\TermCheckFailedException;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The matching contract of ADR-238, one case per sentence of it.
 */
#[CoversClass(TermMatcher::class)]
#[CoversClass(TermLines::class)]
final class TermMatcherTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('matchCases')]
    public function aTermMatchesWhatTheContractSays(string $term, string $text, bool $expected): void
    {
        $matcher = new TermMatcher();

        self::assertSame($expected, $matcher->occursIn($term, $matcher->searchForms($text)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matchCases(): iterable
    {
        yield 'same case' => ['Rabatt', 'Ein Rabatt heute', true];
        yield 'other case' => ['Rabatt', 'EIN RABATT HEUTE', true];
        yield 'other case with an umlaut' => ['Prüfung', 'PRÜFUNG bestanden', true];
        yield 'at the very start and end' => ['Rabatt', 'Rabatt', true];
        yield 'before punctuation' => ['Rabatt', 'Kein Rabatt, nie.', true];
        yield 'a longer word does not match' => ['Kunde', 'Das Kundenkonto', false];
        yield 'a word ending in the term does not match' => ['Konto', 'Das Kundenkonto', false];
        yield 'an umlaut is a letter of the word' => ['Bar', 'Die Bär', false];
        yield 'a following umlaut is part of the word' => ['Mus', 'Musär', false];
        yield 'a digit is part of the word' => ['GPT', 'GPT4', false];
        yield 'an underscore is part of the word' => ['term', 'a term_list', false];
        yield 'no inflection' => ['Rabatt', 'Viele Rabatte', false];
        yield 'a star reaches further letters' => ['Rabatt*', 'Viele Rabatte', true];
        yield 'a star reaches a compound' => ['Rabatt*', 'Die Rabattaktion', true];
        yield 'a star also matches the bare term' => ['Rabatt*', 'Ein Rabatt', true];
        yield 'a star does not reach in front' => ['Rabatt*', 'Der Sonderrabatt', false];
        yield 'ß and ss stay different' => ['Straße', 'Strasse', false];
        yield 'a space matches a line break' => ['bester Preis', "Der bester\nPreis", true];
        yield 'a space matches several spaces' => ['bester Preis', 'bester   Preis', true];
        yield 'a space matches a non-breaking space' => ['bester Preis', "bester\u{00A0}Preis", true];
        yield 'a term with regex characters is literal' => ['C++', 'Wir nutzen C++ hier', true];
        yield 'a dot in a term is a dot' => ['a.b', 'axb', false];
        yield 'a slash in a term' => ['und/oder', 'Das und/oder das', true];
        yield 'a tag does not hide a term' => ['Rabatt', 'Ein Ra<b>batt</b> heute', true];
        yield 'an entity does not hide a term' => ['Prüfung', 'Die Pr&uuml;fung', true];
        yield 'a soft hyphen does not hide a term' => ['Rabatt', "Ein Ra\u{00AD}batt", true];
        yield 'an attribute value is searched as delivered' => ['Rabatt', '<a title="Rabatt">x</a>', true];
    }

    #[Test]
    public function aDecomposedLetterEqualsTheComposedOne(): void
    {
        if (!class_exists(Normalizer::class)) {
            self::markTestSkipped('The intl extension is not loaded.');
        }

        $matcher = new TermMatcher();

        self::assertTrue($matcher->occursIn('Prüfung', $matcher->searchForms("Pru\u{0308}fung")));
    }

    #[Test]
    public function linesBecomeTerms(): void
    {
        self::assertSame(
            ['Rabatt', 'bester Preis', 'Aktion*'],
            TermLines::parse("Rabatt\r\n\n  bester Preis  \n*\n * \nAktion*\nRabatt\n"),
        );
    }

    #[Test]
    public function aLoneLessThanSignDoesNotEatTheRestOfTheText(): void
    {
        $matcher = new TermMatcher();

        self::assertSame(5, $matcher->wordCount('Lieferzeit <5 Tage, garantiert <b>heute</b>'));
    }

    #[Test]
    public function aPatternEngineFailureIsNotAnAbsence(): void
    {
        $matcher = new TermMatcher();

        $this->expectException(TermCheckFailedException::class);

        // Invalid UTF-8 makes a /u pattern return false, which must not read
        // as "the term does not occur".
        $matcher->occursIn('Rabatt', ["\xC3\x28"]);
    }
}
