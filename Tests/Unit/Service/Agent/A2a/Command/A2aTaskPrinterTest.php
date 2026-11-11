<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Command;

use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aTaskPrinter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Remote text reaches the operator's terminal only as plain text.
 */
#[CoversClass(A2aTaskPrinter::class)]
final class A2aTaskPrinterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileText(): iterable
    {
        yield 'C0 escape sequence' => ["a\e[2Jb", 'a[2Jb'];
        yield 'C1 CSI as a character (U+009B)' => ["a\u{9B}2Jb", 'a2Jb'];
        // Invalid UTF-8 is replaced (mbstring's substitute character, `?`)
        // before the pattern runs, so the byte cannot pass as a character.
        yield 'C1 CSI as a lone byte' => ["a\x9B2Jb", 'a?2Jb'];
        yield 'bidi override (U+202E)' => ["file\u{202E}txt.exe", 'filetxt.exe'];
        yield 'zero-width space (U+200B)' => ["a\u{200B}b", 'ab'];
        yield 'line break kept, tab and carriage return removed' => ["a\nb\tc\rd", "a\nbcd"];
        yield 'markup shown as text' => ['<error>x</error>', '\\<error\\>x\\</error\\>'];
        yield 'ordinary text unchanged' => ['Grüße, 東京', 'Grüße, 東京'];
    }

    #[Test]
    #[DataProvider('hostileText')]
    public function remoteTextIsPlain(string $remote, string $expected): void
    {
        self::assertSame($expected, A2aTaskPrinter::plain($remote));
    }
}
