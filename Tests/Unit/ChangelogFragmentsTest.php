<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Drives Build/Scripts/changelog-fragments.php against throwaway git
 * repositories.
 *
 * Fragments replace direct edits of the shared [Unreleased] section, which made
 * every open PR conflict with every merge. The release commit is the only place
 * that reads them, so the check also refuses fragments when the topmost
 * released section has no tag yet.
 */
#[CoversNothing]
final class ChangelogFragmentsTest extends AbstractUnitTestCase
{
    private const CHANGELOG = "# Changelog\n\n## [Unreleased]\n\n"
        . "### Added\n\n- An added line.\n\n"
        . "### Fixed\n\n- A fixed line.\n\n"
        . "## [1.2.0] - 2026-01-01\n\n### Fixed\n\n- Released.\n\n"
        . "[Unreleased]: https://example.org/compare/v1.2.0...HEAD\n";

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/nrllm-changelog-fragments-' . bin2hex(random_bytes(6));
        mkdir($this->repo . '/changelog.d', 0o777, true);
        $this->git('init', '--quiet');
        $this->git('config', 'user.email', 'test@example.org');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->git('config', 'tag.gpgsign', 'false');
        $this->git('commit', '--quiet', '--allow-empty', '-m', 'release');
        $this->git('tag', 'v1.2.0');
        file_put_contents($this->repo . '/CHANGELOG.md', self::CHANGELOG);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->repo);
        parent::tearDown();
    }

    #[Test]
    public function collectAppendsUnderTheExistingHeadingAndDeletesTheFragment(): void
    {
        $this->fragment('feat-x.fixed.md', "- A new fix.\n  Wrapped line.\n");

        [$exit] = $this->runScript('collect');

        self::assertSame(0, $exit);
        self::assertSame(
            str_replace("- A fixed line.\n", "- A fixed line.\n\n- A new fix.\n  Wrapped line.\n", self::CHANGELOG),
            $this->changelog(),
        );
        self::assertFileDoesNotExist($this->repo . '/changelog.d/feat-x.fixed.md');
        self::assertFileDoesNotExist($this->repo . '/CHANGELOG.md.collect');
    }

    #[Test]
    public function collectKeepsTheFragmentsWhenTheChangelogCannotBeWritten(): void
    {
        $this->fragment('feat-x.fixed.md', "- A new fix.\n");
        // A directory where the temporary file belongs makes the write fail
        // for every user, root included.
        mkdir($this->repo . '/CHANGELOG.md.collect');

        [$exit, , $stderr] = $this->runScript('collect');

        self::assertSame(2, $exit);
        self::assertStringContainsString('it and the fragments are unchanged', $stderr);
        self::assertSame(self::CHANGELOG, $this->changelog());
        self::assertFileExists($this->repo . '/changelog.d/feat-x.fixed.md');
    }

    #[Test]
    public function collectInsertsAMissingHeadingInKeepAChangelogOrder(): void
    {
        $this->fragment('a.removed.md', "- A removal.\n");
        $this->fragment('b.security.md', "- A security fix.\n");

        [$exit] = $this->runScript('collect');

        self::assertSame(0, $exit);
        self::assertSame(
            "# Changelog\n\n## [Unreleased]\n\n"
            . "### Added\n\n- An added line.\n\n"
            . "### Removed\n\n- A removal.\n\n"
            . "### Fixed\n\n- A fixed line.\n\n"
            . "### Security\n\n- A security fix.\n\n"
            . "## [1.2.0] - 2026-01-01\n\n### Fixed\n\n- Released.\n\n"
            . "[Unreleased]: https://example.org/compare/v1.2.0...HEAD\n",
            $this->changelog(),
        );
    }

    #[Test]
    public function collectKeepsFragmentsOfOneHeadingInFileNameOrder(): void
    {
        $this->fragment('z-last.added.md', "- Second.\n");
        $this->fragment('a-first.added.md', "- First.\n");

        $this->runScript('collect');

        self::assertStringContainsString("- An added line.\n\n- First.\n\n- Second.\n\n### Fixed", $this->changelog());
    }

    #[Test]
    public function anUpgradingFragmentGetsAHeadingNamedAfterTheLastRelease(): void
    {
        $this->fragment('a.upgrading.md', "- Run the wizard.\n");

        $this->runScript('collect');

        self::assertStringContainsString(
            "## [Unreleased]\n\n### Upgrading from 1.2\n\n- Run the wizard.\n\n### Added",
            $this->changelog(),
        );
    }

    #[Test]
    public function anUpgradingFragmentJoinsAnExistingUpgradingHeading(): void
    {
        file_put_contents(
            $this->repo . '/CHANGELOG.md',
            str_replace("### Added\n", "### Upgrading from 1.1\n\n- Old note.\n\n### Added\n", self::CHANGELOG),
        );
        $this->fragment('a.upgrading.md', "- New note.\n");

        $this->runScript('collect');

        self::assertStringContainsString(
            "### Upgrading from 1.1\n\n- Old note.\n\n- New note.\n\n### Added",
            $this->changelog(),
        );
        self::assertSame(1, substr_count($this->changelog(), '### Upgrading'));
    }

    #[Test]
    public function collectFindsAHeadingInACrlfChangelog(): void
    {
        // A Windows checkout with core.autocrlf: the heading line ends in "\r".
        file_put_contents($this->repo . '/CHANGELOG.md', str_replace("\n", "\r\n", self::CHANGELOG));
        $this->fragment('feat-x.fixed.md', "- A new fix.\n");

        [$exit] = $this->runScript('collect');

        $changelog = $this->changelog();
        self::assertSame(0, $exit);
        self::assertSame(2, substr_count($changelog, '### Fixed'), 'one in [Unreleased], one in [1.2.0]');
        self::assertMatchesRegularExpression('/- A fixed line\.\r?\n\r?\n- A new fix\.\n/', $changelog);
    }

    #[Test]
    public function collectWithoutFragmentsChangesNothing(): void
    {
        file_put_contents($this->repo . '/changelog.d/README.md', "# Fragments\n");

        [$exit, $stdout] = $this->runScript('collect');

        self::assertSame(0, $exit);
        self::assertStringContainsString('nothing to collect', $stdout);
        self::assertSame(self::CHANGELOG, $this->changelog());
        self::assertFileExists($this->repo . '/changelog.d/README.md');
    }

    #[Test]
    public function checkAcceptsWellFormedFragmentsBesideTheReadme(): void
    {
        file_put_contents($this->repo . '/changelog.d/README.md', "# Fragments\n");
        $this->fragment('fix-thing.fixed.md', "- A fix.\n");

        [$exit, $stdout] = $this->runScript('check');

        self::assertSame(0, $exit);
        self::assertStringContainsString('1 fragment(s) ok', $stdout);
    }

    #[Test]
    public function checkRefusesAFragmentWithAnUnknownSection(): void
    {
        $this->fragment('fix-thing.bugfix.md', "- A fix.\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(1, $exit);
        self::assertStringContainsString('fix-thing.bugfix.md  is not named', $stderr);
    }

    #[Test]
    public function checkRefusesAFragmentThatIsNoBullet(): void
    {
        $this->fragment('fix-thing.fixed.md', "A fix.\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(1, $exit);
        self::assertStringContainsString('must start with a top-level bullet', $stderr);
    }

    #[Test]
    public function checkRefusesAFragmentWithAHeading(): void
    {
        $this->fragment('fix-thing.fixed.md', "- A fix.\n\n### Fixed\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(1, $exit);
        self::assertStringContainsString('must not contain a heading', $stderr);
    }

    #[Test]
    public function collectWritesNothingWhenAFragmentIsMalformed(): void
    {
        $this->fragment('good.fixed.md', "- A fix.\n");
        $this->fragment('bad.fixed.md', "No bullet.\n");

        [$exit] = $this->runScript('collect');

        self::assertSame(1, $exit);
        self::assertSame(self::CHANGELOG, $this->changelog());
        self::assertFileExists($this->repo . '/changelog.d/good.fixed.md');
    }

    #[Test]
    public function checkRefusesFragmentsInAReleaseCommit(): void
    {
        // The release commit renamed [Unreleased] to [1.3.0], which has no tag
        // yet, and did not collect the fragment: 1.3.0 would ship without it.
        file_put_contents(
            $this->repo . '/CHANGELOG.md',
            str_replace('## [Unreleased]', "## [Unreleased]\n\n## [1.3.0] - 2026-02-01", self::CHANGELOG),
        );
        $this->fragment('fix-thing.fixed.md', "- A fix.\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(1, $exit);
        self::assertStringContainsString('[1.3.0] has no tag v1.3.0 yet, and 1 fragment(s) are left', $stderr);
        self::assertStringContainsString('composer changelog:collect', $stderr);
        self::assertStringContainsString('push tag v1.3.0', $stderr);
    }

    #[Test]
    public function checkAcceptsFragmentsWhenTheReleaseTagExistsOnlyOnOrigin(): void
    {
        // A CI checkout has no tags. The tag is looked up on origin without
        // being fetched, so a full clone does not turn shallow.
        $origin = $this->repo . '/.git/test-origin.git';
        $this->git('init', '--quiet', '--bare', $origin);
        $this->git('remote', 'add', 'origin', $origin);
        $this->git('tag', 'v1.3.0');
        $this->git('push', '--quiet', 'origin', 'refs/tags/v1.3.0');
        $this->git('tag', '-d', 'v1.3.0');
        file_put_contents(
            $this->repo . '/CHANGELOG.md',
            str_replace('## [Unreleased]', "## [Unreleased]\n\n## [1.3.0] - 2026-02-01", self::CHANGELOG),
        );
        $this->fragment('fix-thing.fixed.md', "- A fix.\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(0, $exit, $stderr);
        $this->expectException(RuntimeException::class);
        $this->git('rev-parse', '--quiet', '--verify', 'refs/tags/v1.3.0');
    }

    #[Test]
    public function checkStopsWhenOriginCannotBeAsked(): void
    {
        $this->git('remote', 'add', 'origin', $this->repo . '/.git/missing.git');
        file_put_contents(
            $this->repo . '/CHANGELOG.md',
            str_replace('## [Unreleased]', "## [Unreleased]\n\n## [1.3.0] - 2026-02-01", self::CHANGELOG),
        );
        $this->fragment('fix-thing.fixed.md', "- A fix.\n");

        [$exit, , $stderr] = $this->runScript('check');

        self::assertSame(2, $exit);
        self::assertStringContainsString('cannot ask origin whether tag v1.3.0 exists', $stderr);
        self::assertStringNotContainsString('has no tag', $stderr);
    }

    #[Test]
    public function aReleaseCommitWithoutFragmentsPasses(): void
    {
        file_put_contents(
            $this->repo . '/CHANGELOG.md',
            str_replace('## [Unreleased]', "## [Unreleased]\n\n## [1.3.0] - 2026-02-01", self::CHANGELOG),
        );

        [$exit] = $this->runScript('check');

        self::assertSame(0, $exit);
    }

    private function fragment(string $name, string $text): void
    {
        file_put_contents($this->repo . '/changelog.d/' . $name, $text);
    }

    private function changelog(): string
    {
        return (string)file_get_contents($this->repo . '/CHANGELOG.md');
    }

    /**
     * Run the script in $mode against the throwaway repository.
     *
     * @return array{int, string, string}
     */
    private function runScript(string $mode): array
    {
        $script = dirname(__DIR__, 2) . '/Build/Scripts/changelog-fragments.php';
        // nosemgrep: netresearch.php.lang.security.exec-use.exec-use - argv array, no shell; runs PHP or git on a throwaway test repository
        $proc = proc_open([PHP_BINARY, $script, $mode, $this->repo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the script', 1790260001);
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    private function git(string ...$args): void
    {
        $command = array_values(['git', '-C', $this->repo, ...$args]);
        // nosemgrep: netresearch.php.lang.security.exec-use.exec-use - argv array, no shell; runs PHP or git on a throwaway test repository
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start git', 1790260002);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException(implode(' ', $command) . ' failed: ' . $stderr, 1790260003);
        }
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            // nosemgrep: php.lang.security.unlink-use.unlink-use - removes the test's own temporary directory
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
