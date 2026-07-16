<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\ExcerptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcerptBuilder::class)]
final class ExcerptBuilderTest extends TestCase
{
    #[Test]
    public function stripsTagsAndCollapsesWhitespace(): void
    {
        self::assertSame(
            'Hello TYPO3 world',
            ExcerptBuilder::plain("<p>Hello\n\n  <b>TYPO3</b>\tworld</p>"),
        );
    }

    #[Test]
    public function separatesAdjacentTextNodesAtTagBoundaries(): void
    {
        self::assertSame(
            'Price 100 Total 200',
            ExcerptBuilder::plain('<tr><td>Price</td><td>100</td></tr><tr><td>Total</td><td>200</td></tr>'),
        );
    }

    #[Test]
    public function adjacentBlockElementsYieldSingleSpaces(): void
    {
        $plain = ExcerptBuilder::plain("<h1>Title</h1>\n<p>Intro <b>text</b></p><p>Next</p>");

        self::assertSame('Title Intro text Next', $plain);
        self::assertStringNotContainsString('  ', $plain);
    }

    #[Test]
    public function centresExcerptOnTheMatchWithEllipses(): void
    {
        $text = str_repeat('lorem ipsum ', 30) . 'Netresearch Migration' . str_repeat(' dolor sit', 30);

        $excerpt = ExcerptBuilder::around($text, 'netresearch', 60);

        self::assertStringContainsString('Netresearch', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
        self::assertLessThanOrEqual(62, mb_strlen($excerpt));
    }

    #[Test]
    public function fallsBackToTextHeadWhenQueryDoesNotOccur(): void
    {
        $excerpt = ExcerptBuilder::around('Short intro text about something.', 'missing-term');

        self::assertStringStartsWith('Short intro', $excerpt);
    }

    #[Test]
    public function emptyTextYieldsEmptyExcerpt(): void
    {
        self::assertSame('', ExcerptBuilder::around('   ', 'query'));
    }
}
