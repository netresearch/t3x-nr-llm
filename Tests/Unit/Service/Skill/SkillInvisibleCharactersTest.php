<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\SkillInvisibleCharacters;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * What a skill version may contain before an approver can approve it
 * (ADR-214 item 2): every character the model reads must be visible on the
 * review page.
 */
#[CoversClass(SkillInvisibleCharacters::class)]
final class SkillInvisibleCharactersTest extends TestCase
{
    #[Test]
    public function ordinaryMarkdownWithLayoutWhitespaceIsClean(): void
    {
        self::assertSame([], SkillInvisibleCharacters::findIn([
            'body' => "# Title\n\n- item\twith a tab\r\n```\ncode\n```\nUmlaute äöü, emoji 😀, CJK 漢字.",
        ]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hiddenCharacters(): iterable
    {
        yield 'tag character (LLM-readable, invisible)' => ["a\u{E0041}b", 'U+E0041'];
        yield 'zero-width space' => ["a\u{200B}b", 'U+200B'];
        yield 'right-to-left override' => ["a\u{202E}b", 'U+202E'];
        yield 'bidi isolate' => ["a\u{2066}b", 'U+2066'];
        yield 'no-break space' => ["a\u{00A0}b", 'U+00A0'];
        yield 'soft hyphen' => ["a\u{00AD}b", 'U+00AD'];
        yield 'byte-order mark' => ["a\u{FEFF}b", 'U+FEFF'];
        yield 'line separator' => ["a\u{2028}b", 'U+2028'];
        yield 'NUL control' => ["a\u{0000}b", 'U+0000'];
        yield 'Hangul filler' => ["a\u{3164}b", 'U+3164'];
    }

    #[Test]
    #[DataProvider('hiddenCharacters')]
    public function aHiddenCharacterIsReportedWithFieldLineAndCodePoint(string $text, string $codePoint): void
    {
        self::assertSame(['body, line 2: ' . $codePoint], SkillInvisibleCharacters::findIn(['body' => "first line\n" . $text]));
    }

    #[Test]
    public function everyFieldIsChecked(): void
    {
        self::assertSame(
            ['name, line 1: U+200B', 'description, line 1: U+E0020'],
            SkillInvisibleCharacters::findIn(['name' => "Gui\u{200B}de", 'description' => "x\u{E0020}", 'body' => 'clean']),
        );
    }

    #[Test]
    public function invalidUtf8IsReportedRatherThanPassed(): void
    {
        self::assertSame(['body: not valid UTF-8'], SkillInvisibleCharacters::findIn(['body' => "a\xC3b"]));
    }

    #[Test]
    public function aLongListIsCappedAndCounted(): void
    {
        $findings = SkillInvisibleCharacters::findIn(['body' => str_repeat("x\u{E0041}", 25)]);

        self::assertCount(21, $findings);
        self::assertSame('… and 5 more', $findings[20]);
    }

    /**
     * The class is the one the write tools' approval previews use; it is
     * repeated here, so this pins both to the same definition.
     */
    #[Test]
    public function thePatternIsTheWriteToolsInvisibleCharacterClass(): void
    {
        self::assertSame(
            (new ReflectionClassConstant(CopyRecordTool::class, 'INVISIBLE_CHARACTERS'))->getValue(),
            SkillInvisibleCharacters::PATTERN,
        );
    }
}
