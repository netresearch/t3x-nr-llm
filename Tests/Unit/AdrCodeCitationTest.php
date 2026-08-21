<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks the `File.php:NNN` citations in the ADR corpus against the tree.
 *
 * A line number is invalidated by any edit above it in the cited file, and
 * nothing failed when that happened: ADR-171 cited ResumeCoordinator.php:204
 * for an authorisation check that a later commit pushed to :205, and the record
 * went on reading like a verified citation.
 *
 * What this catches, exactly:
 *
 * - a citation naming a file that is no longer in the tree, or whose basename
 *   became ambiguous;
 * - a citation pointing past the end of its file, or at a blank line;
 * - an anchored `path#anchor` citation whose anchor the file no longer holds.
 *
 * What it does NOT catch, stated so nobody reads more into it: a line that
 * moved onto different but non-blank code. That is the common case and the
 * dangerous one — the citation then asserts something the cited code does not
 * say, and its precision is what makes a reviewer trust it. ADR-171 cited
 * AgentRunController.php:267 for "the list viewport"; :267 is that file's
 * closing brace for an unrelated method, and every assertion here passed on it.
 *
 * No line-based test can tell the difference, which is why ADR-171 no longer
 * cites lines at all: a symbol, a `:ref:` into the record it quotes, or a
 * `path#anchor` naming a string the file must still contain. The last of those
 * is the only citation form in this corpus a test can hold to its claim.
 *
 * Only `Adr*.rst` is scanned, and that is load-bearing rather than incidental:
 * `Adr/Index.rst` states the citation convention and quotes two rotted
 * citations as the examples of what not to write. Widening this glob to
 * `*.rst` would fail the build on the page that documents the rule.
 *
 * Citations into TYPO3 core and sibling extensions used to be listed as
 * unverifiable — sixteen of them, in .Build/vendor, whose line numbers move
 * with every patch release of a dependency this repository does not pin. The
 * corpus now names those symbols instead, so the list emptied and went with
 * it: an escape hatch nothing reaches for is a declaration nothing reads.
 * A record that cites a file this suite cannot open now simply fails.
 */
#[CoversNothing]
final class AdrCodeCitationTest extends AbstractUnitTestCase
{
    /** Suffixes an ADR in this corpus cites by line. */
    private const CITED_EXTENSIONS = ['php', 'rst', 'html', 'xlf', 'txt'];

    private const LINE_CITATION = '#([A-Za-z0-9_/.-]+\.(?:php|rst|html|xlf|txt)):(\d+)(?:-(\d+))?#';

    private const ANCHORED_CITATION = '#``([A-Za-z0-9_/.-]+\.(?:php|rst|html|xlf|txt))\#([^`]+)``#';

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every `File.ext:NNN` or `File.ext:NNN-MMM` in the corpus.
     *
     * Restricting this to `.php` left 28% of the corpus's citations unread —
     * ADRs cite templates, XLIFF, the frozen api-surface file and each other by
     * line just as readily, and one of those was pointing at a blank line.
     *
     * @return list<array{adr: string, adrLine: int, path: string, from: int, to: int}>
     */
    private function citations(): array
    {
        $files = glob($this->repositoryRoot() . '/Documentation/Adr/Adr*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $citations = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string)file_get_contents($file));
            foreach ($lines as $index => $line) {
                preg_match_all(self::LINE_CITATION, $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $citations[] = [
                        'adr'     => basename($file),
                        'adrLine' => $index + 1,
                        'path'    => $match[1],
                        'from'    => (int)$match[2],
                        'to'      => (int)($match[3] ?? $match[2]),
                    ];
                }
            }
        }

        return $citations;
    }

    /**
     * Basename => every path under the repository root carrying it.
     *
     * .Build holds the composer install, landingpage a generator with its own
     * tree; neither is what an ADR means when it names a file.
     *
     * @return array<string, list<string>>
     */
    private function basenameIndex(): array
    {
        $root     = $this->repositoryRoot();
        $skip     = ['.Build', '.git', 'landingpage', 'node_modules', 'var', '.ddev'];
        $index    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = ltrim(str_replace($root, '', $entry->getPathname()), '/');
            $segment  = explode('/', $relative)[0];
            if (in_array($segment, $skip, true)) {
                continue;
            }

            if (!$entry->isFile() || !in_array($entry->getExtension(), self::CITED_EXTENSIONS, true)) {
                continue;
            }

            $index[$entry->getBasename()][] = $relative;
        }

        return $index;
    }

    /**
     * The repository file a citation names, or null when it names none.
     *
     * @param array<string, list<string>> $index
     */
    private function resolve(string $path, array $index, string $where): ?string
    {
        if (str_contains($path, '/')) {
            return is_file($this->repositoryRoot() . '/' . $path) ? $path : null;
        }

        $hits = $index[$path] ?? [];
        self::assertLessThan(
            2,
            count($hits),
            sprintf(
                '%s cites "%s" by basename alone and the repository now holds %d files with that name (%s). '
                . 'Cite it with its path so the reference names one file.',
                $where,
                $path,
                count($hits),
                implode(', ', $hits),
            ),
        );

        return $hits[0] ?? null;
    }

    #[Test]
    public function everyCitationNamesAFileThatStillExists(): void
    {
        $index   = $this->basenameIndex();
        $orphans = [];

        foreach ($this->citations() as $citation) {
            $where = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            if ($this->resolve($citation['path'], $index, $where) !== null) {
                continue;
            }

            $orphans[] = sprintf(
                '%s cites %s, which is not a file in this repository. A record cites what this suite '
                . 'can open: a symbol for code we do not ship, never a path into .Build/vendor.',
                $where,
                $citation['path'],
            );
        }

        self::assertSame([], $orphans, implode("\n", $orphans));
    }

    #[Test]
    public function noCitationPointsPastTheEndOfItsFileOrAtABlankLine(): void
    {
        $index   = $this->basenameIndex();
        $stale   = [];

        foreach ($this->citations() as $citation) {
            $where    = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            $resolved = $this->resolve($citation['path'], $index, $where);
            if ($resolved === null) {
                continue;
            }

            $lines = explode("\n", (string)file_get_contents($this->repositoryRoot() . '/' . $resolved));
            foreach ([$citation['from'], $citation['to']] as $number) {
                if ($number > count($lines)) {
                    $stale[] = sprintf('%s cites %s:%d; the file ends at line %d', $where, $resolved, $number, count($lines));
                    continue;
                }

                if (trim($lines[$number - 1]) === '') {
                    $stale[] = sprintf('%s cites %s:%d, which is blank', $where, $resolved, $number);
                }
            }
        }

        self::assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Every `path#anchor` citation in the corpus.
     *
     * @return list<array{adr: string, adrLine: int, path: string, anchor: string}>
     */
    private function anchoredCitations(): array
    {
        $files = glob($this->repositoryRoot() . '/Documentation/Adr/Adr*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $found = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string)file_get_contents($file));
            foreach ($lines as $index => $line) {
                preg_match_all(self::ANCHORED_CITATION, $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $found[] = [
                        'adr'     => basename($file),
                        'adrLine' => $index + 1,
                        'path'    => $match[1],
                        'anchor'  => $match[2],
                    ];
                }
            }
        }

        return $found;
    }

    #[Test]
    public function everyAnchoredCitationNamesAStringItsFileStillContains(): void
    {
        $broken = [];

        $index = $this->basenameIndex();

        foreach ($this->anchoredCitations() as $citation) {
            $where    = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            $resolved = $this->resolve($citation['path'], $index, $where);

            if ($resolved === null) {
                $broken[] = sprintf('%s anchors into %s, which is not in the tree', $where, $citation['path']);
                continue;
            }

            $file = $this->repositoryRoot() . '/' . $resolved;
            if (str_contains((string)file_get_contents($file), $citation['anchor'])) {
                continue;
            }

            $broken[] = sprintf(
                '%s anchors on "%s", which %s no longer contains. Either the code moved on and the '
                . 'record needs rewriting, or the anchor was never unique enough to survive an edit.',
                $where,
                $citation['anchor'],
                $citation['path'],
            );
        }

        self::assertSame([], $broken, implode("\n", $broken));
    }
}
