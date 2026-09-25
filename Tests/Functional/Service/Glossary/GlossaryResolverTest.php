<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Glossary;

use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The site-glossary lookup against the real table (ADR-208).
 */
#[CoversClass(GlossaryResolver::class)]
final class GlossaryResolverTest extends AbstractFunctionalTestCase
{
    private GlossaryResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Glossaries.csv');
        $this->subject = new GlossaryResolver($this->getConnectionPool());
    }

    #[Test]
    public function resolvesTheSitesGlossaryForTheLanguagePair(): void
    {
        $glossary = $this->subject->resolve('main', 'de', 'en');

        self::assertInstanceOf(ResolvedGlossary::class, $glossary);
        self::assertSame(1, $glossary->uid);
        self::assertSame('de', $glossary->sourceLanguage);
        self::assertSame('en', $glossary->targetLanguage);
        // Both separators of the entries field: "=" and a tab.
        self::assertSame(
            ['Warenkorb' => 'shopping cart', 'Kundenkonto' => 'customer account'],
            $glossary->terms->toArray(),
        );
    }

    #[Test]
    public function regionalCodesInTheRequestMatchTheBaseCodeOfTheRecord(): void
    {
        $glossary = $this->subject->resolve('main', 'de-DE', 'EN-gb');

        self::assertInstanceOf(ResolvedGlossary::class, $glossary);
        self::assertSame(1, $glossary->uid);
    }

    #[Test]
    public function anotherSiteGetsItsOwnGlossary(): void
    {
        $glossary = $this->subject->resolve('other', 'de', 'en');

        self::assertInstanceOf(ResolvedGlossary::class, $glossary);
        self::assertSame(5, $glossary->uid);
        self::assertSame(['Warenkorb' => 'cart'], $glossary->terms->toArray());
    }

    #[Test]
    public function theDirectionOfThePairMatters(): void
    {
        // Record 1 is de → en; nothing is stored for en → de on 'main' except a
        // deleted record.
        self::assertNull($this->subject->resolve('main', 'en', 'de'));
    }

    #[Test]
    public function aHiddenGlossaryDoesNotReachATranslation(): void
    {
        self::assertNull($this->subject->resolve('main', 'de', 'fr'));
    }

    #[Test]
    public function aGlossaryWithoutAUsablePairResolvesToNothing(): void
    {
        self::assertNull($this->subject->resolve('main', 'de', 'nl'));
    }

    #[Test]
    public function anUnknownSiteResolvesToNothing(): void
    {
        self::assertNull($this->subject->resolve('nowhere', 'de', 'en'));
    }

    #[Test]
    public function storingADeepLGlossaryWritesBothColumnsOfThatRecordOnly(): void
    {
        $this->subject->storeDeepLGlossary(1, 'gls_test_new', 'hash-one');

        $glossary = $this->subject->resolve('main', 'de', 'en');
        self::assertInstanceOf(ResolvedGlossary::class, $glossary);
        self::assertSame('gls_test_new', $glossary->deeplGlossaryId);
        self::assertSame('hash-one', $glossary->deeplEntriesHash);

        $other = $this->subject->resolve('other', 'de', 'en');
        self::assertInstanceOf(ResolvedGlossary::class, $other);
        self::assertSame('', $other->deeplGlossaryId);
    }

    #[Test]
    public function aDeepLGlossaryHeldByAnotherRecordCountsAsReferencedEvenWhenThatRecordIsHidden(): void
    {
        // Record 8 is hidden and carries the same id as record 7.
        self::assertTrue($this->subject->isDeepLGlossaryReferenced('gls_test_shared', 7));
    }

    #[Test]
    public function aDeepLGlossaryHeldOnlyByTheRecordItselfIsNotReferenced(): void
    {
        $this->subject->storeDeepLGlossary(1, 'gls_test_only', 'hash-one');

        self::assertFalse($this->subject->isDeepLGlossaryReferenced('gls_test_only', 1));
        self::assertTrue($this->subject->isDeepLGlossaryReferenced('gls_test_only', 2));
    }
}
