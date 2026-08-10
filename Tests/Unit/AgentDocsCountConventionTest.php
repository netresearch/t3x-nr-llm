<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps file-count claims out of the AGENTS.md files.
 *
 * They rotted three times before this test existed: "264 PHP source files"
 * against 638, "26 ADRs" against 139, "69 files" against 202, "38 Architecture
 * Decision Records" against the same 139, "9 reference pages" against 15,
 * "7 workflow file(s)" against 12, "13 controllers" against 21.
 *
 * The counts were removed rather than corrected — including three that were
 * right at the time, because the problem is the shape and not the arithmetic.
 * A number that changes when anyone adds a file cannot be asserted without
 * turning every new file into a red build, and it tells a reader nothing an
 * `ls` would not, so the rule is that agent docs name what exists rather than
 * how much of it there is.
 *
 * Counts of things that do NOT change with a file are untouched: seven
 * registered provider adapters, thirteen seeded demo tasks. This test rejects
 * counting files only — see the two patterns below for where that line is
 * drawn.
 */
#[CoversNothing]
final class AgentDocsCountConventionTest extends AbstractUnitTestCase
{
    /**
     * `<number> <file-noun>` — "69 RST files", "26 ADRs", "9 reference pages".
     *
     * Bare `records`, `pages` and `specs` are deliberately absent: "the wizard
     * writes 2 records to sys_log" and "renders 3 pages behind one route"
     * count things, not files, and this rule is only about files.
     */
    private const LEADING_COUNT_PATTERN
        = '/\b\d+\s+(?:'
        . 'files?|ADRs?|'
        . '(?:PHP\s+)?source\s+files?|RST\s+files?|test\s+files?|spec\s+files?|'
        . 'workflow\s+files?|Architecture\s+Decision\s+Records?|reference\s+pages?'
        . ')\b/i';

    /**
     * `<plural-noun> (<number>)` — "controllers (13), request DTOs (4)".
     *
     * The first version of this test missed exactly this shape, which is the
     * one the row it removed used: three of the nine stale numbers came from a
     * single `Controller/Backend/` table cell written this way.
     */
    private const TRAILING_COUNT_PATTERN = '/\b[A-Za-z][A-Za-z]*s\s*\(\d+\)/';

    /**
     * Directories that hold no repository content: composer's install target,
     * git's object store, npm's.
     */
    private const SKIPPED_DIRECTORIES = ['.Build', '.git', 'node_modules'];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string> repo-relative paths
     */
    private static function agentDocs(): array
    {
        $found    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(self::repoRoot(), FilesystemIterator::SKIP_DOTS),
                static fn(SplFileInfo $file): bool => !$file->isDir()
                    || !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true),
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() === 'AGENTS.md') {
                $found[] = substr($file->getPathname(), strlen(self::repoRoot()) + 1);
            }
        }

        sort($found);

        return $found;
    }

    /**
     * One case per document: a count in one file must not mask a count in the
     * next, which a single loop with an assertion inside would do.
     *
     * @return list<array{string}>
     */
    public static function agentDocProvider(): array
    {
        return array_map(static fn(string $doc): array => [$doc], self::agentDocs());
    }

    #[Test]
    public function theSweepFindsEveryAgentDoc(): void
    {
        // Without this the next test passes on an empty list.
        $docs = self::agentDocs();

        self::assertSame(
            [
                '.ddev/AGENTS.md',
                '.github/workflows/AGENTS.md',
                'AGENTS.md',
                'Classes/AGENTS.md',
                'Configuration/AGENTS.md',
                'Documentation/AGENTS.md',
                'Resources/AGENTS.md',
                'Tests/AGENTS.md',
            ],
            $docs,
            'The set of agent docs changed. Adding one is fine — update this list so a DELETED one '
            . 'cannot silently stop being checked.',
        );
    }

    /**
     * The document split into `## ` sections, with the "Working in this repo"
     * narrative dropped.
     *
     * That section recounts what a past run observed ("reproduced the same 53
     * files") — a record of an event, not a claim about the tree. Splitting
     * rather than matching a range keeps the exclusion to exactly that section:
     * the root file grows by appending `## … (agent notes)` sections, and a
     * range that ran to end-of-file would exempt every future one.
     *
     * @return list<string>
     */
    private function sectionsExcludingTheNarrative(string $contents): array
    {
        $sections = preg_split('/^(?=## )/m', $contents);
        self::assertIsArray($sections);

        return array_values(array_filter(
            $sections,
            static fn(string $section): bool => !str_starts_with($section, '## Working in this repo'),
        ));
    }

    #[Test]
    #[DataProvider('agentDocProvider')]
    public function noAgentDocCountsFiles(string $doc): void
    {
        $contents = (string)file_get_contents(self::repoRoot() . '/' . $doc);

        $body = implode('', $this->sectionsExcludingTheNarrative($contents));

        $found = [];
        foreach ([self::LEADING_COUNT_PATTERN, self::TRAILING_COUNT_PATTERN] as $pattern) {
            preg_match_all($pattern, $body, $matches);
            $found = array_merge($found, $matches[0]);
        }

        self::assertSame(
            [],
            $found,
            $doc . ' counts files: "' . implode('", "', $found) . '". Name what exists instead — '
            . "these counts have rotted three times. See this test's docblock.",
        );
    }
}
