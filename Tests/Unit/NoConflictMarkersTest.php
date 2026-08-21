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
 * No file in this repository carries a merge conflict marker.
 *
 * `Documentation/Adr/Index.rst` shipped one in v0.32.0. A hand-resolved merge
 * (`41bb809a`, "Merge branch 'main' into fix/mcp-accept-sse") kept both sides
 * and left the diff3 BASE marker behind as the file's last line, so the ADR
 * toctree ended on `||||||| c6503022`. Nothing failed: every suite passed, the
 * docs job rendered, the release went out.
 *
 * The reason nothing failed is worth stating, because it is what makes this
 * test necessary rather than redundant. The other guards read STRUCTURE — the
 * lifecycle test reads status fields, the citation test reads citations, the
 * docs job renders a page. A stray line at the end of a toctree is none of
 * those things to any of them. Only a check that reads the bytes finds it.
 *
 * The `<<<<<<<` and `>>>>>>>` markers are checked too, although the case that
 * happened was the middle one: a resolution that drops those two and forgets
 * the base marker is exactly the shape that survives a careless `git add -A`,
 * because the file no longer looks conflicted at a glance.
 */
#[CoversNothing]
final class NoConflictMarkersTest extends AbstractUnitTestCase
{
    /**
     * Directories that are not this repository's source: the composer install,
     * git's own storage, the JS toolchain, and the docs render output.
     *
     * @var list<string>
     */
    private const SKIPPED = ['.Build', '.git', 'node_modules', 'var', '.ddev', 'Documentation-GENERATED-temp'];

    /**
     * A line that opens, bases or closes a conflict hunk. Anchored at the start
     * of the line and requiring the trailing space git writes, so a row of
     * seven equals signs underlining an RST heading is not mistaken for one —
     * which is why `=======` is deliberately NOT in this list.
     */
    private const MARKERS = ['<<<<<<< ', '||||||| ', '>>>>>>> '];

    #[Test]
    public function noTrackedFileCarriesAConflictMarker(): void
    {
        $root  = dirname(__DIR__, 2);
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = ltrim(str_replace($root, '', $entry->getPathname()), '/');
            if (in_array(explode('/', $relative)[0], self::SKIPPED, true)) {
                continue;
            }

            if (!$entry->isFile() || $entry->getSize() > 2_000_000) {
                continue;
            }

            // This file names the markers it looks for and would report itself.
            if ($relative === 'Tests/Unit/NoConflictMarkersTest.php') {
                continue;
            }

            $lines = explode("\n", (string)file_get_contents($entry->getPathname()));
            foreach ($lines as $number => $line) {
                foreach (self::MARKERS as $marker) {
                    if (str_starts_with($line, $marker)) {
                        $found[] = sprintf('%s:%d  %s', $relative, $number + 1, $line);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $found,
            "A merge conflict marker reached the tree. A resolution that keeps both sides and drops only the\n"
            . "<<<<<<< and >>>>>>> lines leaves the base marker behind, and the file stops looking conflicted:\n"
            . implode("\n", $found),
        );
    }

    /**
     * The guard is only worth having if it fires, and the shape it has to catch
     * is the one that already got through: a lone base marker with no opening
     * and no closing line.
     */
    #[Test]
    public function aLoneBaseMarkerIsRecognised(): void
    {
        $line = '||||||| c6503022';

        $hit = false;
        foreach (self::MARKERS as $marker) {
            $hit = $hit || str_starts_with($line, $marker);
        }

        self::assertTrue($hit, 'The marker that shipped in v0.32.0 must be one this test recognises.');
    }

    /**
     * An RST heading underline is seven or more equals signs at the start of a
     * line, which is why `=======` is not a marker here. Stated as a test so
     * nobody "completes" the list and turns every heading in the corpus into a
     * finding.
     */
    #[Test]
    public function anRstHeadingUnderlineIsNotAConflictMarker(): void
    {
        foreach (self::MARKERS as $marker) {
            self::assertStringStartsNotWith(
                $marker,
                '=======',
                'An RST underline must not match a conflict marker.',
            );
        }
    }
}
