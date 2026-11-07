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
 * Drives Build/Scripts/check-agent-doc-symlinks.php against throwaway trees.
 *
 * The project ships AGENTS.md and no CLAUDE.md: Claude Code reads AGENTS.md
 * itself, but only where no CLAUDE.md exists — a CLAUDE.md beside an AGENTS.md
 * makes it read the CLAUDE.md alone. GEMINI.md stays a symlink beside every
 * AGENTS.md, because Gemini CLI reads GEMINI.md by default. Each test builds
 * the tree it needs, runs the script as `composer ci:test:agent-docs` does and
 * reads its exit code and message.
 */
#[CoversNothing]
final class AgentDocAliasCheckTest extends AbstractUnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/nrllm-agent-alias-check-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    #[Test]
    public function anAgentsFileWithItsGeminiSymlinkPasses(): void
    {
        $this->scope('.');
        $this->scope('Classes');

        [$exit, $stderr] = $this->check();

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function documentationNeedsNoGeminiSymlink(): void
    {
        // The docs renderer aborts on any symlink under Documentation/.
        $this->scope('.');
        mkdir($this->root . '/Documentation');
        file_put_contents($this->root . '/Documentation/AGENTS.md', "# Docs\n");

        [$exit, $stderr] = $this->check();

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function aMissingGeminiSymlinkFails(): void
    {
        $this->scope('.');
        mkdir($this->root . '/Classes');
        file_put_contents($this->root . '/Classes/AGENTS.md', "# Classes\n");

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Classes/GEMINI.md: missing', $stderr);
    }

    #[Test]
    public function aClaudeSymlinkBesideAgentsFails(): void
    {
        $this->scope('.');
        $this->scope('Classes');
        symlink('AGENTS.md', $this->root . '/Classes/CLAUDE.md');

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Classes/CLAUDE.md: must not exist', $stderr);
    }

    #[Test]
    public function aClaudeFileInTheExemptDocumentationTreeFails(): void
    {
        // The exemption covers the GEMINI.md symlink only; the regular
        // `@AGENTS.md` import file that used to live here is refused too.
        $this->scope('.');
        mkdir($this->root . '/Documentation');
        file_put_contents($this->root . '/Documentation/AGENTS.md', "# Docs\n");
        file_put_contents($this->root . '/Documentation/CLAUDE.md', "@AGENTS.md\n");

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Documentation/CLAUDE.md: must not exist', $stderr);
    }

    #[Test]
    public function aLocalClaudeDirectoryIsNotInspected(): void
    {
        // `.claude/` is gitignored and personal; a developer's own
        // `.claude/CLAUDE.md` must not fail the pre-commit hook.
        $this->scope('.');
        mkdir($this->root . '/.claude');
        file_put_contents($this->root . '/.claude/CLAUDE.md', "# mine\n");

        [$exit, $stderr] = $this->check();

        self::assertSame(0, $exit, $stderr);
    }

    /**
     * An AGENTS.md with its GEMINI.md symlink in $directory below the root.
     */
    private function scope(string $directory): void
    {
        $path = $directory === '.' ? $this->root : $this->root . '/' . $directory;
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        file_put_contents($path . '/AGENTS.md', "# Agents\n");
        symlink('AGENTS.md', $path . '/GEMINI.md');
    }

    /**
     * @return array{int, string}
     */
    private function check(): array
    {
        $script = dirname(__DIR__, 2) . '/Build/Scripts/check-agent-doc-symlinks.php';
        $proc   = proc_open([PHP_BINARY, $script, $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the check', 1794070001);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
