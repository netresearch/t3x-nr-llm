<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(SkillMarkdownParser::class)]
final class SkillMarkdownBoundaryTest extends TestCase
{
    #[Test]
    public function dashedYamlKeyCannotTerminateTheMetadataBlock(): void
    {
        $parsed = (new SkillMarkdownParser())->parse(
            'SKILL.md',
            "---\nname: guide\ndescription: Review\n---notes: Keep this metadata\nallowed-tools: []\n---\nBody.\n",
        );
        self::assertSame(
            [
                'name' => 'guide',
                'description' => 'Review',
                '---notes' => 'Keep this metadata',
                'allowed-tools' => [],
            ],
            $parsed->rawFrontmatter,
        );
        self::assertSame("Body.\n", $parsed->body);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function malformedClosingLines(): iterable
    {
        yield 'suffix' => ['---suffix'];
        yield 'four dashes' => ['----'];
        yield 'same-line body' => ['--- body'];
        yield 'text after whitespace' => ["--- \t body"];
    }

    #[Test]
    #[DataProvider('malformedClosingLines')]
    public function closingMarkerMustOccupyItsOwnLine(string $closing): void
    {
        $this->expectException(SkillParseException::class);
        $this->expectExceptionMessage('missing YAML front-matter');
        (new SkillMarkdownParser())->parse(
            'SKILL.md',
            "---\nname: guide\ndescription: Review\n" . $closing . "\nBody.\n",
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function validClosingLines(): iterable
    {
        yield 'empty body at EOF' => ["---\nname: guide\ndescription: Review\n---", ''];
        yield 'CRLF body' => [
            "---\r\nname: guide\r\ndescription: Review\r\n---\r\nBody.\r\n",
            "Body.\r\n",
        ];
        yield 'later body separator' => [
            "---\nname: guide\ndescription: Review\n---\nBody.\n---\nNext.\n",
            "Body.\n---\nNext.\n",
        ];
        yield 'closing whitespace at EOF' => ["---\nname: guide\ndescription: Review\n--- \t", ''];
        yield 'closing whitespace before LF' => ["---\nname: guide\ndescription: Review\n--- \t\nBody.\n", "Body.\n"];
        yield 'closing whitespace before CRLF' => [
            "---\r\nname: guide\r\ndescription: Review\r\n--- \t\r\nBody.\r\n",
            "Body.\r\n",
        ];
    }

    #[Test]
    #[DataProvider('validClosingLines')]
    public function standaloneClosingMarkerPreservesTheEntireBody(
        string $content,
        string $body,
    ): void {
        $parsed = null;
        $failure = null;
        try {
            $parsed = (new SkillMarkdownParser())->parse('SKILL.md', $content);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull(
            $failure,
            'A supported delimiter must not refuse this skill.',
        );
        self::assertNotNull($parsed);
        self::assertSame('guide', $parsed->name);
        self::assertSame('Review', $parsed->description);
        self::assertSame($body, $parsed->body);
    }
}
