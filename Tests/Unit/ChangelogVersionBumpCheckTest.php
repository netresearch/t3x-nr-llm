<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Drives Build/Scripts/check-changelog-version-bump.php against throwaway
 * CHANGELOG files.
 *
 * The check exists because 0.44.0 to 0.55.0 raised the middle version number
 * for releases without a breaking change. Under 0.x, `^0.54` admits only
 * 0.54.*, so each of those releases made the dependent extensions widen their
 * constraint and release. The rule in Documentation/Api/Stability.rst: the
 * middle number rises only for a release with a breaking entry, and a patch
 * release has none.
 */
#[CoversNothing]
final class ChangelogVersionBumpCheckTest extends AbstractUnitTestCase
{
    private const HEAD = "# Changelog\n\n## [Unreleased]\n\n";

    private const BASE = "## [0.55.1] - 2026-11-25\n\n### Added\n\n- A feature.\n\n"
        . "## [0.55.0] - 2026-11-24\n\n### Added\n\n- Another feature.\n\n"
        . "## [0.54.0] - 2026-11-23\n\n### Added\n\n- A third feature.\n\n"
        . "[Unreleased]: https://example.org/compare/v0.55.1...HEAD\n";

    private const BREAKING = "- **BREAKING: `Foo::bar()` is removed.** Call `Foo::baz()` instead.\n";

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/nrllm-version-bump-' . bin2hex(random_bytes(6)) . '.md';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    #[Test]
    public function theRealChangelogPasses(): void
    {
        [$exit, $stderr] = $this->check((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'));

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aMiddleBumpWithoutABreakingEntryFails(): void
    {
        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', "### Added\n\n- A new feature.\n"));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.56.0]  raises the middle number over 0.55.1 without a breaking entry', $stderr);
        self::assertStringContainsString('release it as 0.55.2', $stderr);
    }

    #[Test]
    public function aMiddleBumpWithABreakingEntryPasses(): void
    {
        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', "### Changed\n\n" . self::BREAKING));

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aPatchBumpWithoutABreakingEntryPasses(): void
    {
        [$exit, $stderr] = $this->check($this->withTopmost('0.55.2', "### Added\n\n- A new feature.\n"));

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aPatchBumpWithABreakingEntryFails(): void
    {
        [$exit, $stderr] = $this->check($this->withTopmost('0.55.2', "### Changed\n\n" . self::BREAKING));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.55.2]  has a breaking entry but keeps the middle number of 0.55.1', $stderr);
        self::assertStringContainsString('release it as 0.56.0', $stderr);
    }

    #[Test]
    public function onlyATopLevelBulletOpeningWithTheMarkerCounts(): void
    {
        // The word inside a sentence or a nested bullet is not the marker: the
        // check and the reader have to find the same line.
        $body = "### Changed\n\n- A change that is **BREAKING** for nobody.\n  - **BREAKING: nested.**\n";

        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', $body));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.56.0]  raises the middle number', $stderr);
    }

    #[Test]
    public function releasesUpToTheRuleAreNotJudged(): void
    {
        // 0.55.0 raised the middle number over 0.54.0 without a breaking entry,
        // before the rule existed. Released sections are frozen, so judging
        // them could never pass.
        [$exit, $stderr] = $this->check(self::HEAD . self::BASE);

        self::assertSame(0, $exit, $stderr);
    }

    private function withTopmost(string $version, string $body): string
    {
        return self::HEAD . '## [' . $version . "] - 2026-12-01\n\n" . $body . "\n" . self::BASE;
    }

    /**
     * Run the check against $changelog as CI does.
     *
     * @return array{int, string}
     */
    private function check(string $changelog): array
    {
        file_put_contents($this->file, $changelog);

        $script  = dirname(__DIR__, 2) . '/Build/Scripts/check-changelog-version-bump.php';
        $command = [PHP_BINARY, $script, $this->file];
        $proc    = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the check', 1790260001);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }
}
