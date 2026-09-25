<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlossaryTerms::class)]
final class GlossaryTermsTest extends TestCase
{
    #[Test]
    public function parsesBothSeparatorsAndTrimsEachSide(): void
    {
        $terms = GlossaryTerms::fromText("  Warenkorb =  shopping cart \nKundenkonto\tcustomer account\r\nKasse=checkout");

        self::assertSame(
            ['Warenkorb' => 'shopping cart', 'Kundenkonto' => 'customer account', 'Kasse' => 'checkout'],
            $terms->toArray(),
        );
        self::assertSame(3, $terms->count());
        self::assertFalse($terms->isEmpty());
    }

    #[Test]
    public function aTabSeparatedLineKeepsAnEqualsSignInsideTheTerm(): void
    {
        $terms = GlossaryTerms::fromText("a = b\tA equals B");

        self::assertSame(['a = b' => 'A equals B'], $terms->toArray());
    }

    #[Test]
    public function withoutATabOnlyTheFirstEqualsSignSeparates(): void
    {
        $terms = GlossaryTerms::fromText('x = y = z');

        self::assertSame(['x' => 'y = z'], $terms->toArray());
    }

    #[Test]
    public function linesThatCannotBeAPairAreSkipped(): void
    {
        $terms = GlossaryTerms::fromText(implode("\n", [
            '',
            '# a comment = not a pair',
            'no separator here',
            ' = missing source',
            'missing target = ',
            "three\tcolumns\tline",
            'kept = yes',
        ]));

        self::assertSame(['kept' => 'yes'], $terms->toArray());
    }

    #[Test]
    public function aRepeatedSourceTermKeepsItsLastTranslation(): void
    {
        $terms = GlossaryTerms::fromText("Warenkorb = basket\nWarenkorb = shopping cart");

        self::assertSame(['Warenkorb' => 'shopping cart'], $terms->toArray());
    }

    #[Test]
    public function anEmptyTextYieldsNoTerms(): void
    {
        $terms = GlossaryTerms::fromText("\n# only a comment\n");

        self::assertTrue($terms->isEmpty());
        self::assertSame(0, $terms->count());
        self::assertSame('', $terms->toTsv());
    }

    #[Test]
    public function rendersDeepLsTsvFormat(): void
    {
        $terms = GlossaryTerms::fromText("Warenkorb = shopping cart\nKasse = checkout");

        self::assertSame("Warenkorb\tshopping cart\nKasse\tcheckout", $terms->toTsv());
    }
}
