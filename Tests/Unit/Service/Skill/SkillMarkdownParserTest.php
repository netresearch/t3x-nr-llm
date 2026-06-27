<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillMarkdownParser::class)]
final class SkillMarkdownParserTest extends TestCase
{
    private SkillMarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SkillMarkdownParser();
    }

    #[Test]
    public function parsesNameDescriptionAndBody(): void
    {
        $content = "---\nname: My Skill\ndescription: Does things\n---\nBody line one.\n";
        $parsed = $this->parser->parse('SKILL.md', $content);
        self::assertSame('My Skill', $parsed->name);
        self::assertSame('Does things', $parsed->description);
        self::assertSame('Body line one.', trim($parsed->body));
        self::assertSame(SupportStatus::FULL, $parsed->supportStatus);
    }

    #[Test]
    public function parsesContentWithLeadingByteOrderMark(): void
    {
        $content = "\xEF\xBB\xBF---\nname: My Skill\ndescription: Does things\n---\nBody line one.\n";
        $parsed = $this->parser->parse('SKILL.md', $content);
        self::assertSame('My Skill', $parsed->name);
        self::assertSame('Does things', $parsed->description);
        self::assertSame('Body line one.', trim($parsed->body));
    }

    #[Test]
    public function flagsPartialWhenBodyReferencesScripts(): void
    {
        $content = "---\nname: S\ndescription: d\n---\nRun `scripts/audit.py` then continue.\n";
        $parsed = $this->parser->parse('SKILL.md', $content);
        self::assertSame(SupportStatus::PARTIAL, $parsed->supportStatus);
        self::assertNotSame('', $parsed->unsupportedNotes);
    }

    #[Test]
    public function throwsOnMissingName(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('SKILL.md', "---\ndescription: d\n---\nbody\n");
    }

    #[Test]
    public function throwsOnAbsentFrontmatter(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('SKILL.md', "no frontmatter here\n");
    }

    #[Test]
    public function throwsOnMalformedYaml(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('SKILL.md', "---\nname: [unterminated\n---\nbody\n");
    }

    #[Test]
    public function throwsOnListFrontmatter(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('SKILL.md', "---\n- a\n- b\n---\nbody\n");
    }

    #[Test]
    public function throwsWhenBodyExceedsSizeCap(): void
    {
        $parser = new SkillMarkdownParser(maxBodyBytes: 16);
        $this->expectException(SkillParseException::class);
        $parser->parse('SKILL.md', "---\nname: n\ndescription: d\n---\n" . str_repeat('x', 100));
    }

    #[Test]
    public function throwsOnNonUtf8Content(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('SKILL.md', "---\nname: n\ndescription: d\n---\n" . "\xff\xfe");
    }
}
