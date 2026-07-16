<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tag-boundary spacing of the tools' plain-text excerpts: naive strip_tags
 * concatenates adjacent text nodes ('<td>Price</td><td>100</td>' becomes
 * 'Price100'), so each excerpt helper inserts a space at tag boundaries
 * before stripping and collapses the whitespace afterwards. The excerpt
 * helpers touch no collaborator (formatHit only the dependency-free
 * TableReadAccessService), so newInstanceWithoutConstructor is safe.
 */
#[CoversClass(ProbeUrlTool::class)]
#[CoversClass(GetPageContentTool::class)]
#[CoversClass(SearchRecordsTool::class)]
final class ExcerptTagSpacingTest extends TestCase
{
    /**
     * @param class-string $class
     * @param list<mixed>  $arguments
     */
    private static function invokeExcerptHelper(string $class, string $method, array $arguments): string
    {
        $reflection = new ReflectionClass($class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        if ($class === SearchRecordsTool::class) {
            // formatHit resolves the label field through the (stateless)
            // table-access denylist; initialise only that dependency.
            $reflection->getProperty('tableAccess')->setValue($instance, new TableReadAccessService());
        }

        $result = $reflection->getMethod($method)->invoke($instance, ...$arguments);
        self::assertIsString($result);

        return $result;
    }

    #[Test]
    public function probeUrlBodyExcerptSeparatesAdjacentTableCells(): void
    {
        $excerpt = self::invokeExcerptHelper(
            ProbeUrlTool::class,
            'bodyExcerpt',
            ['<table><tr><td>Price</td><td>100</td></tr></table>'],
        );

        self::assertSame('Price 100', $excerpt);
    }

    #[Test]
    public function probeUrlBodyExcerptSeparatesAdjacentBlockElementsWithoutDoubleSpaces(): void
    {
        $excerpt = self::invokeExcerptHelper(
            ProbeUrlTool::class,
            'bodyExcerpt',
            ["<h1>Title</h1>\n<p>Intro <b>text</b></p><p>Next</p>"],
        );

        self::assertSame('Title Intro text Next', $excerpt);
        self::assertStringNotContainsString('  ', $excerpt);
    }

    #[Test]
    public function probeUrlBodyExcerptKeepsTheByteCap(): void
    {
        $excerpt = self::invokeExcerptHelper(
            ProbeUrlTool::class,
            'bodyExcerpt',
            ['<td>' . str_repeat('a', 3000) . '</td>'],
        );

        // 2048 capped bytes plus the appended ellipsis.
        self::assertSame(2048 + strlen('…'), strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }

    #[Test]
    public function pageContentExcerptSeparatesAdjacentTableCells(): void
    {
        $excerpt = self::invokeExcerptHelper(
            GetPageContentTool::class,
            'excerpt',
            ['<tr><td>Price</td><td>100</td></tr><tr><td>Total</td><td>200</td></tr>'],
        );

        self::assertSame('Price 100 Total 200', $excerpt);
    }

    #[Test]
    public function pageContentExcerptKeepsTheLengthCap(): void
    {
        $excerpt = self::invokeExcerptHelper(
            GetPageContentTool::class,
            'excerpt',
            ['<p>' . str_repeat('a', 250) . '</p>'],
        );

        // 200 capped characters plus the appended ellipsis.
        self::assertSame(201, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }

    #[Test]
    public function searchRecordsMatchExcerptSeparatesAdjacentTableCells(): void
    {
        $hit = self::invokeExcerptHelper(
            SearchRecordsTool::class,
            'formatHit',
            [
                'tt_content',
                ['uid' => 7, 'pid' => 1, 'bodytext' => '<tr><td>Price</td><td>100</td></tr>'],
                ['bodytext'],
                'Price 100',
            ],
        );

        // Before the fix the plain text was 'Price100', so a query spanning
        // the cell boundary produced no match excerpt at all.
        self::assertStringContainsString('tt_content:7', $hit);
        self::assertStringContainsString('match(bodytext): Price 100', $hit);
    }
}
