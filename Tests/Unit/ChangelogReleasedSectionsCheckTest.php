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
 * Drives Build/Scripts/check-changelog-released-sections.php against throwaway
 * git repositories.
 *
 * The check exists because the merge queue combined #970 with the release
 * commit of 0.37.0 and git put #970's entry inside the released [0.37.0]
 * section, while the only CHANGELOG check read [Unreleased] alone. Each test
 * builds the tags it needs, runs the script as CI does, and reads its exit
 * code and message.
 */
#[CoversNothing]
final class ChangelogReleasedSectionsCheckTest extends AbstractUnitTestCase
{
    private const LINKS = "[Unreleased]: https://example.org/compare/v1.1.0...HEAD\n"
        . "[1.1.0]: https://example.org/compare/v1.0.0...v1.1.0\n";

    private const AT_1_0_0 = "# Changelog\n\n## [Unreleased]\n\n"
        . "## [1.0.0] - 2026-01-01\n\n### Added\n\n- The first feature.\n\n"
        . "[Unreleased]: https://example.org/compare/v1.0.0...HEAD\n";

    private const AT_1_1_0 = "# Changelog\n\n## [Unreleased]\n\n"
        . "## [1.1.0] - 2026-01-02\n\n### Fixed\n\n- The first fix.\n\n"
        . "## [1.0.0] - 2026-01-01\n\n### Added\n\n- The first feature.\n\n"
        . self::LINKS;

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/nrllm-changelog-check-' . bin2hex(random_bytes(6));
        mkdir($this->repo);
        $this->git('init', '--quiet');
        $this->git('config', 'user.email', 'test@example.org');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->git('config', 'tag.gpgsign', 'false');
        $this->release(self::AT_1_0_0, 'v1.0.0');
        $this->release(self::AT_1_1_0, 'v1.1.0');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->repo);
        parent::tearDown();
    }

    #[Test]
    public function releasedSectionsThatMatchTheirTagsPass(): void
    {
        [$exit] = $this->check(self::AT_1_1_0);

        self::assertSame(0, $exit);
    }

    #[Test]
    public function anEntryThatLandsInAReleasedSectionFails(): void
    {
        // The #970 shape: [Unreleased] is empty and the new entry sits under
        // the release that does not contain it.
        $changelog = str_replace('- The first fix.', "- The first fix.\n- A later fix.", self::AT_1_1_0);

        [$exit, $stderr] = $this->check($changelog);

        self::assertSame(1, $exit);
        self::assertStringContainsString('[1.1.0]  differs from the section at tag v1.1.0', $stderr);
        self::assertStringContainsString('move the added entry back under [Unreleased]', $stderr);
        self::assertStringNotContainsString('[1.0.0]', $stderr);
    }

    #[Test]
    public function theTopmostSectionMayPrecedeItsTag(): void
    {
        // A release PR adds [1.2.0] before v1.2.0 is pushed.
        $changelog = str_replace(
            '## [1.1.0]',
            "## [1.2.0] - 2026-01-03\n\n### Added\n\n- The next feature.\n\n## [1.1.0]",
            self::AT_1_1_0,
        );

        [$exit] = $this->check($changelog);

        self::assertSame(0, $exit);
    }

    #[Test]
    public function anOlderSectionWithoutAReadableTagFails(): void
    {
        // No origin to fetch from, so the missing tag stays missing.
        $this->git('tag', '-d', 'v1.0.0');

        [$exit, $stderr] = $this->check(self::AT_1_1_0);

        self::assertSame(1, $exit);
        self::assertStringContainsString('[1.0.0]  tag v1.0.0 cannot be read', $stderr);
        self::assertStringContainsString('fetch the tags', $stderr);
        self::assertStringNotContainsString('back under [Unreleased]', $stderr);
    }

    #[Test]
    public function theLinkBlockBelongsToNoSection(): void
    {
        $changelog = str_replace(self::LINKS, self::LINKS . "[1.0.0]: https://example.org/releases/v1.0.0\n", self::AT_1_1_0);

        [$exit] = $this->check($changelog);

        self::assertSame(0, $exit);
    }

    #[Test]
    public function aPinnedDeviationPassesOnlyWhileItIsUnchanged(): void
    {
        // [0.7.0] is pinned in KNOWN_DEVIATIONS (its tag carries no
        // CHANGELOG.md) and is the last section of the real file, so it runs
        // from its heading to the first link line.
        $real = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $start = strpos($real, '## [0.7.0]');
        $end = strpos($real, "\n[Unreleased]: ");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $section = substr($real, $start, $end + 1 - $start);

        [$unchanged] = $this->check("# Changelog\n\n## [Unreleased]\n\n" . $section . self::LINKS);
        [$changed, $stderr] = $this->check("# Changelog\n\n## [Unreleased]\n\n" . $section . "- An added line.\n\n" . self::LINKS);

        self::assertSame(0, $unchanged);
        self::assertSame(1, $changed);
        self::assertStringContainsString('[0.7.0]  changed since it was pinned as a known deviation', $stderr);
    }

    /**
     * Run the check against $changelog in the throwaway repository.
     *
     * @return array{int, string}
     */
    private function check(string $changelog): array
    {
        $path = $this->repo . '/CHANGELOG.md';
        file_put_contents($path, $changelog);

        $script = dirname(__DIR__, 2) . '/Build/Scripts/check-changelog-released-sections.php';
        $proc = proc_open([PHP_BINARY, $script, $path, $this->repo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the check', 1790250001);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }

    private function release(string $changelog, string $tag): void
    {
        file_put_contents($this->repo . '/CHANGELOG.md', $changelog);
        $this->git('add', 'CHANGELOG.md');
        $this->git('commit', '--quiet', '-m', 'release ' . $tag);
        $this->git('tag', $tag);
    }

    private function git(string ...$args): void
    {
        $proc = proc_open(array_merge(['git', '-C', $this->repo], array_values($args)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start git', 1790250002);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException('git ' . implode(' ', $args) . ' failed: ' . $stderr, 1790250003);
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
