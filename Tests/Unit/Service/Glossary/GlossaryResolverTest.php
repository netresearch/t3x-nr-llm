<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Glossary;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The database-free half of the site-glossary lookup (ADR-208); the lookup
 * itself runs against the real table in the functional GlossaryResolverTest.
 */
#[CoversClass(GlossaryResolver::class)]
#[CoversClass(ResolvedGlossary::class)]
final class GlossaryResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languageCodes(): iterable
    {
        yield 'base code' => ['de', 'de'];
        yield 'upper case' => ['DE', 'de'];
        yield 'region with hyphen' => ['de-DE', 'de'];
        yield 'region with underscore' => ['pt_BR', 'pt'];
        yield 'lower-case region' => ['en-gb', 'en'];
        yield 'surrounding whitespace' => [' fr ', 'fr'];
        yield 'three letters' => ['deu', ''];
        yield 'one letter' => ['d', ''];
        yield 'empty' => ['', ''];
        yield 'sentinel' => ['auto', ''];
    }

    #[Test]
    #[DataProvider('languageCodes')]
    public function reducesALanguageCodeToItsBaseCode(string $code, string $expected): void
    {
        self::assertSame($expected, GlossaryResolver::baseLanguage($code));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unanswerableLookups(): iterable
    {
        yield 'no site' => ['', 'de', 'en'];
        yield 'no source' => ['main', '', 'en'];
        yield 'no target' => ['main', 'de', ''];
        yield 'unusable source' => ['main', 'auto', 'en'];
    }

    #[Test]
    #[DataProvider('unanswerableLookups')]
    public function aLookupThatCannotMatchAnyRecordDoesNotQuery(string $site, string $source, string $target): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        self::assertNull((new GlossaryResolver($connectionPool))->resolve($site, $source, $target));
    }

    #[Test]
    public function theEntriesHashCoversTheTermsAndTheLanguagePair(): void
    {
        $terms = GlossaryTerms::fromText('Warenkorb = shopping cart');
        $hash  = (new ResolvedGlossary(1, 'de', 'en', $terms))->entriesHash();

        // Same record, same content: the same hash, whatever the stored id.
        self::assertSame($hash, (new ResolvedGlossary(1, 'de', 'en', $terms, 'gls_test_1', 'old'))->entriesHash());
        // A changed term, or the same terms for another pair, is another glossary.
        self::assertNotSame($hash, (new ResolvedGlossary(1, 'de', 'en', GlossaryTerms::fromText('Warenkorb = basket')))->entriesHash());
        self::assertNotSame($hash, (new ResolvedGlossary(1, 'de', 'fr', $terms))->entriesHash());
        self::assertNotSame($hash, (new ResolvedGlossary(1, 'nl', 'en', $terms))->entriesHash());
    }
}
