<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the (manually maintained) extension version surfaces against drift.
 *
 * The version number lives in several files that are bumped by hand on every
 * release: ext_emconf.php (authoritative in-repo), composer.json
 * (extra.typo3/cms.version — required since the TYPO3 v14.2 ext_emconf
 * deprecation #108345 to silence it while staying v13.4-compatible), and
 * Documentation/guides.xml. The release workflow derives the published version
 * from the git tag and does NOT validate these in-repo files, so this test is
 * the safety net: if a release bump forgets one surface, CI fails here.
 *
 * The same drift applies one level up, to the SUPPORTED versions rather than
 * the released one. Documentation/Api/SupportMatrix.rst promises which TYPO3
 * and PHP versions nr_llm runs on; composer.json, ext_emconf.php and the CI
 * matrix decide it. A published matrix nobody checks is worse than none, so
 * the same idea is applied: the page states the literals and this test asserts
 * them against the three sources.
 */
#[CoversNothing]
final class VersionConsistencyTest extends AbstractUnitTestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function extEmConfVersion(): string
    {
        $contents = file_get_contents($this->repoRoot() . '/ext_emconf.php');
        self::assertIsString($contents, 'ext_emconf.php must be readable');
        self::assertSame(
            1,
            preg_match("/'version'\\s*=>\\s*'([^']+)'/", $contents, $matches),
            'ext_emconf.php must declare a version',
        );

        return $matches[1];
    }

    #[Test]
    public function composerJsonVersionMatchesExtEmconf(): void
    {
        $composer = json_decode((string)file_get_contents($this->repoRoot() . '/composer.json'), true);
        self::assertIsArray($composer);

        $composerVersion = $composer['extra']['typo3/cms']['version'] ?? null;

        self::assertSame(
            $this->extEmConfVersion(),
            $composerVersion,
            'composer.json extra.typo3/cms.version must match ext_emconf.php version '
            . '(TYPO3 v14.2 ext_emconf deprecation #108345 — keep both in sync on every release bump).',
        );
    }

    #[Test]
    public function guidesXmlVersionMatchesExtEmconf(): void
    {
        $version = $this->extEmConfVersion();
        $guides = (string)file_get_contents($this->repoRoot() . '/Documentation/guides.xml');

        self::assertStringContainsString(
            'version="' . $version . '"',
            $guides,
            'Documentation/guides.xml version attribute must match ext_emconf.php version.',
        );
        self::assertStringContainsString(
            'release="' . $version . '"',
            $guides,
            'Documentation/guides.xml release attribute must match ext_emconf.php version.',
        );
    }

    // ==================== support matrix ====================

    #[Test]
    public function supportMatrixMatchesComposerRequirements(): void
    {
        $composer = json_decode((string)file_get_contents($this->repoRoot() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);

        // Equality would be the obvious assertion and it is wrong in CI: the
        // shared workflow narrows `typo3/cms-core` in composer.json to ONE
        // matrix cell before it installs, so the file this test reads says
        // `^13.4` in a job the repository declares `^13.4 || ^14.3` for. That
        // narrowing is still worth asserting — a cell must not test a version
        // the matrix does not promise — so the required constraint has to be
        // the documented range, or one of the alternatives it is built from.
        $documented = $this->supportMatrixField('composer typo3/cms-core');
        self::assertCount(1, $documented);

        $required = $composer['require']['typo3/cms-core'];
        self::assertIsString($required);

        $alternatives = array_map(trim(...), explode('||', $documented[0]));

        self::assertContains(
            $required,
            [$documented[0], ...$alternatives],
            'Documentation/Api/SupportMatrix.rst promises "' . $documented[0]
            . '" and composer.json requires "' . $required
            . '", which is neither that range nor one of the versions it is built from.',
        );

        self::assertSame(
            [$composer['require']['php']],
            $this->supportMatrixField('composer php'),
            'Documentation/Api/SupportMatrix.rst promises a PHP range composer.json does not require.',
        );
    }

    #[Test]
    public function supportMatrixMatchesExtEmconfDepends(): void
    {
        $emconf = (string)file_get_contents($this->repoRoot() . '/ext_emconf.php');

        foreach (['typo3', 'php'] as $key) {
            self::assertSame(
                1,
                preg_match("/'" . $key . "'\\s*=>\\s*'([^']+)'/", $emconf, $matches),
                'ext_emconf.php must declare a ' . $key . ' constraint.',
            );

            self::assertSame(
                [$matches[1]],
                $this->supportMatrixField('ext_emconf ' . $key),
                'Documentation/Api/SupportMatrix.rst states a different TER ' . $key
                . ' range than ext_emconf.php declares.',
            );
        }
    }

    /**
     * The CI matrix is the only one of the three sources that is a set rather
     * than a range, and it is spread over several workflow calls: the main
     * one, the MariaDB functional leg, and the merge queue's reduced PHP set.
     * The union is what the extension is actually tested against, so that is
     * what the page must name — a single cell is a subset by design.
     */
    #[Test]
    public function supportMatrixMatchesTheCiMatrix(): void
    {
        $workflow = (string)file_get_contents($this->repoRoot() . '/.github/workflows/ci.yml');

        self::assertSame(
            $this->ciMatrixUnion($workflow, 'php-versions', '/"(\d+\.\d+)"/'),
            $this->supportMatrixField('ci php-versions'),
            'Documentation/Api/SupportMatrix.rst lists PHP versions the CI matrix in '
            . '.github/workflows/ci.yml does not run (or omits ones it does).',
        );

        self::assertSame(
            $this->ciMatrixUnion($workflow, 'typo3-versions', '/"(\^?[\d.]+)"/'),
            $this->supportMatrixField('ci typo3-versions'),
            'Documentation/Api/SupportMatrix.rst lists TYPO3 constraints the CI matrix in '
            . '.github/workflows/ci.yml does not run (or omits ones it does).',
        );
    }

    /**
     * Every value the named matrix key carries anywhere in the workflow.
     *
     * @return list<string> sorted and deduplicated
     */
    private function ciMatrixUnion(string $workflow, string $key, string $valuePattern): array
    {
        self::assertGreaterThan(
            0,
            preg_match_all('/^\s*' . preg_quote($key, '/') . ':\s*(.*)$/m', $workflow, $lines),
            '.github/workflows/ci.yml declares no ' . $key . ' — the matrix moved.',
        );

        $values = [];
        foreach ($lines[1] as $line) {
            preg_match_all($valuePattern, $line, $found);
            foreach ($found[1] as $value) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        self::assertNotSame([], $values, 'No ' . $key . ' values parsed out of .github/workflows/ci.yml.');

        return $values;
    }

    /**
     * The double-backticked values of one field in the machine-checked block
     * of Documentation/Api/SupportMatrix.rst.
     *
     * @return list<string> sorted, so a reordered list is not a failure
     */
    private function supportMatrixField(string $name): array
    {
        $page = (string)file_get_contents($this->repoRoot() . '/Documentation/Api/SupportMatrix.rst');

        $start = strpos($page, '.. support-matrix-start');
        $end   = strpos($page, '.. support-matrix-end');
        self::assertNotFalse($start, 'SupportMatrix.rst must carry the .. support-matrix-start marker.');
        self::assertNotFalse($end, 'SupportMatrix.rst must carry the .. support-matrix-end marker.');
        self::assertGreaterThan($start, $end, 'The support-matrix markers are in the wrong order.');

        $block = substr($page, $start, $end - $start);

        self::assertSame(
            1,
            preg_match('/^:' . preg_quote($name, '/') . ':\s*(.+)$/m', $block, $field),
            'SupportMatrix.rst must declare exactly one `' . $name . '` field between its markers.',
        );

        preg_match_all('/``([^`]+)``/', $field[1], $values);
        self::assertNotSame([], $values[1], 'The `' . $name . '` field lists no ``value``.');

        $listed = $values[1];
        sort($listed);

        return $listed;
    }
}
