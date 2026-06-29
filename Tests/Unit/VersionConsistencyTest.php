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
}
