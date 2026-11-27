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
use RuntimeException;

/**
 * Drives Build/Scripts/check-changelog-version-bump.php against throwaway
 * CHANGELOG files and changelog.d/ directories.
 *
 * The check exists because 0.45.0 to 0.55.0 raised the middle version number
 * eleven times without a breaking entry. Under 0.x, `^0.54` admits only
 * 0.54.*, so each such release requires the dependent extensions to widen
 * their constraint and release. The rule in Documentation/Api/Stability.rst:
 * the middle number rises by exactly one, to 0.N+1.0, only for a release with
 * a `- **BREAKING` bullet, and a release that keeps it has none.
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

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/nrllm-version-bump-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/changelog.d', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->dir . '/changelog.d') as $file) {
            unlink((string) $file);
        }

        rmdir($this->dir . '/changelog.d');
        if (is_file($this->dir . '/CHANGELOG.md')) {
            unlink($this->dir . '/CHANGELOG.md');
        }

        rmdir($this->dir);
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
    public function aMiddleBumpMustBeExactlyOneToAZeroPatch(): void
    {
        [$skipped, $skippedErr] = $this->check($this->withTopmost('0.57.0', "### Changed\n\n" . self::BREAKING));
        [$patched, $patchedErr] = $this->check($this->withTopmost('0.56.1', "### Changed\n\n" . self::BREAKING));

        self::assertSame(1, $skipped);
        self::assertStringContainsString('[0.57.0]  follows 0.55.1, so a middle bump is 0.56.0', $skippedErr);
        self::assertSame(1, $patched);
        self::assertStringContainsString('[0.56.1]  follows 0.55.1, so a middle bump is 0.56.0', $patchedErr);
    }

    #[Test]
    public function theHighestEarlierVersionIsThePredecessorNotTheSectionBelow(): void
    {
        // A section written out of order: 0.57.0 follows 0.56.0, not the
        // 0.55.2 that happens to stand below it.
        $changelog = self::HEAD
            . "## [0.57.0] - 2026-12-03\n\n### Changed\n\n" . self::BREAKING . "\n"
            . "## [0.55.2] - 2026-12-01\n\n### Fixed\n\n- A fix.\n\n"
            . "## [0.56.0] - 2026-12-02\n\n### Changed\n\n" . self::BREAKING . "\n"
            . self::BASE;

        [$exit, $stderr] = $this->check($changelog);

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aVersionThatIsNotPlainFailsInsteadOfSwitchingTheCheckOff(): void
    {
        // Before, an unparseable version was skipped and the release below it
        // was compared with nothing.
        $changelog = self::HEAD
            . "## [0.56.0-rc.1] - 2026-12-02\n\n### Added\n\n- A new feature.\n\n"
            . "## [0.56.0] - 2026-12-01\n\n### Added\n\n- A new feature.\n\n"
            . self::BASE;

        [$exit, $stderr] = $this->check($changelog);

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.56.0-rc.1]  is not a plain N.N.N version', $stderr);
        self::assertStringContainsString('[0.56.0]  raises the middle number over 0.55.1 without a breaking entry', $stderr);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lookalikes(): array
    {
        return [
            'title case'        => ["- **Breaking:** `Foo::bar()` is removed.\n"],
            'inside a bullet'   => ["- `WriteKind` gains `DELETED`. **BREAKING for exhaustive matches:** add an arm.\n"],
            'nested bullet'     => ["- A change.\n  - **BREAKING: nested.**\n"],
            'continuation line' => ["- A change.\n  **Breaking:** `Foo` takes a third argument.\n"],
            'heading'           => ["### BREAKING\n\n- `Foo::bar()` is removed.\n"],
            'plain bullet'      => ["- BREAKING: `Foo::bar()` is removed.\n"],
            'underscore bold'   => ["- __BREAKING:__ `Foo::bar()` is removed.\n"],
            'italic'            => ["- *BREAKING* `Foo::bar()` is removed.\n"],
            'longer word'       => ["- **BREAKING_NOTE:** `Foo::bar()` is removed.\n"],
            'digit after'       => ["- **BREAKING2:** `Foo::bar()` is removed.\n"],
            'prose'             => ["- This is not a breaking change for callers.\n"],
            'twice on a marker' => ["- **BREAKING: `Foo` changed,** breaking for implementers too.\n"],
        ];
    }

    #[Test]
    #[DataProvider('lookalikes')]
    public function aLookalikeOfTheMarkerFailsWithTheExpectedForm(string $body): void
    {
        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', "### Changed\n\n" . self::BREAKING . $body));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.56.0]  line ', $stderr);
        self::assertStringContainsString('is only allowed on a marker line: a top-level bullet that opens with `- **BREAKING`', $stderr);
    }

    #[Test]
    public function theMarkerQuotedAsCodeIsNoLookalike(): void
    {
        $body = "### Changed\n\n- Entries of that kind open with `**BREAKING` now, and a `breaking` in code is prose.\n";

        [$exit, $stderr] = $this->check($this->withTopmost('0.55.2', $body));

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aLookalikeInUnreleasedOrInAFragmentFailsBeforeTheRelease(): void
    {
        $unreleased               = "# Changelog\n\n## [Unreleased]\n\n### Changed\n\n- **Breaking:** `Foo` changed.\n\n" . self::BASE;
        [$inSection, $sectionErr] = $this->check($unreleased);

        file_put_contents($this->dir . '/changelog.d/a-branch.changed.md', "- **Breaking:** `Foo` changed.\n");
        [$inFragment, $fragmentErr] = $this->check(self::HEAD . self::BASE);

        self::assertSame(1, $inSection);
        self::assertStringContainsString('[Unreleased]  line ', $sectionErr);
        self::assertSame(1, $inFragment);
        self::assertStringContainsString('changelog.d/a-branch.changed.md  line 1', $fragmentErr);
    }

    #[Test]
    public function aFragmentWithTheMarkerPasses(): void
    {
        file_put_contents($this->dir . '/changelog.d/a-branch.removed.md', self::BREAKING);

        [$exit, $stderr] = $this->check(self::HEAD . self::BASE);

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aHeadingThatIsNoPlainVersionFails(): void
    {
        // releasedSections() reads only headings that start with a digit, so a
        // `v` prefix used to drop the section from the check entirely.
        [$exit, $stderr] = $this->check($this->withTopmost('v0.56.0', "### Added\n\n- A new feature.\n"));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[v0.56.0]  is not a plain N.N.N version', $stderr);
    }

    #[Test]
    public function aLookalikeIsReportedAtItsLineInTheFile(): void
    {
        // HEAD is four lines, the heading and a blank line two more, then
        // "### Changed", a blank line, the marker and the lookalike on line 10.
        $body = "### Changed\n\n" . self::BREAKING . "- **Breaking:** `Foo` changed.\n";

        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', $body));

        self::assertSame(1, $exit);
        self::assertStringContainsString('[0.56.0]  line 10:', $stderr);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fences(): array
    {
        return ['backticks' => ['```'], 'tildes' => ['~~~']];
    }

    #[Test]
    #[DataProvider('fences')]
    public function aMarkerInsideAFencedCodeBlockIsNeitherMarkerNorLookalike(string $fence): void
    {
        $fenced = "### Changed\n\n- An example of the format:\n\n  " . $fence . "markdown\n  - **Breaking:** x\n"
            . '  ' . $fence . "\n" . $fence . "\n" . self::BREAKING . $fence . "\n";

        [$patch, $patchErr]   = $this->check($this->withTopmost('0.55.2', $fenced));
        [$middle, $middleErr] = $this->check($this->withTopmost('0.56.0', $fenced));

        self::assertSame(0, $patch, $patchErr);
        self::assertSame(1, $middle);
        self::assertStringContainsString('[0.56.0]  raises the middle number', $middleErr);
    }

    #[Test]
    public function aLongerWordIsNoMarkerAndIsRefused(): void
    {
        // The word may stand on a marker line only, so a longer word is
        // refused too: the strict rule leaves no variant to slip through.
        $body = "### Changed\n\n- **BREAKINGLY fast** parsing.\n- **breakingnews** is a channel name.\n";

        [$patch, $patchErr]   = $this->check($this->withTopmost('0.55.2', $body));
        [$middle, $middleErr] = $this->check($this->withTopmost('0.56.0', $body));

        self::assertSame(1, $patch);
        self::assertSame(2, substr_count($patchErr, 'is only allowed on a marker line'));
        self::assertSame(1, $middle);
        self::assertStringContainsString('without a breaking entry', $middleErr);
    }

    #[Test]
    public function aReleaseHeadingWithoutBracketsFails(): void
    {
        // A heading without brackets is not split off as a section, so it
        // would hide the release from the check.
        $changelog = self::HEAD . "## 0.56.0 - 2026-12-01\n\n### Added\n\n- A new feature.\n\n" . self::BASE;

        [$exit, $stderr] = $this->check($changelog);

        self::assertSame(1, $exit);
        self::assertStringContainsString('## 0.56.0 - 2026-12-01', $stderr);
        self::assertStringContainsString('only `## [Unreleased]` and `## [N.N.N]', $stderr);
    }

    #[Test]
    public function aLargeReportDoesNotBlockTheHelper(): void
    {
        // Well over a pipe buffer of stderr: the helper must not wait on one
        // pipe while the script blocks on the other.
        $body = "### Changed\n\n" . self::BREAKING . str_repeat("- **Breaking:** `Foo` changed.\n", 3000);

        [$exit, $stderr] = $this->check($this->withTopmost('0.56.0', $body));

        self::assertSame(1, $exit);
        self::assertGreaterThan(65536, strlen($stderr));
    }

    #[Test]
    public function releasesUpToTheRuleAreNotJudged(): void
    {
        // 0.55.0 raised the middle number over 0.54.0 without a breaking entry,
        // before the rule existed. Released sections are frozen, so judging
        // them could never pass. Older lookalikes stay where they are too.
        $base = str_replace('- Another feature.', "- Another feature.\n  **Breaking:** an old form.", self::BASE);

        [$exit, $stderr] = $this->check(self::HEAD . $base);

        self::assertSame(0, $exit, $stderr);
    }

    private function withTopmost(string $version, string $body): string
    {
        return self::HEAD . '## [' . $version . "] - 2026-12-01\n\n" . $body . "\n" . self::BASE;
    }

    /**
     * Run the check against $changelog as CI does; changelog.d/ sits beside it.
     *
     * @return array{int, string}
     */
    private function check(string $changelog): array
    {
        $path = $this->dir . '/CHANGELOG.md';
        file_put_contents($path, $changelog);

        $script  = dirname(__DIR__, 2) . '/Build/Scripts/check-changelog-version-bump.php';
        $command = [PHP_BINARY, $script, $path];
        // stdout goes to /dev/null: reading one pipe to its end while the script
        // blocks on a full other pipe would hang the test.
        $proc = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the check', 1790260001);
        }

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }
}
