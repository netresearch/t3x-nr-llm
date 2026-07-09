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
