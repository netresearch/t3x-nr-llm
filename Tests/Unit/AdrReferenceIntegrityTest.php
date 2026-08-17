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
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every ADR named by filename anywhere in the repository must resolve.
 *
 * {@see AdrLifecycleTest} already binds the records to each other — status
 * words, the `:Amended:` / `:Superseded:` pairing, and that the index resolves
 * every record it names. All of that stays inside `Documentation/Adr`. What
 * nothing checked is a reference from OUTSIDE it, and that is where the rot
 * was: `AGENTS.md` sent readers to `Adr001ThreeTierProviderArchitecture` and
 * `Adr012ApiKeyStorageVault`, neither of which exists, and the landing page
 * published the second one as the `evidence` URL of a security control, where
 * it rendered as a public 404 (#795).
 *
 * Those two names are written WITHOUT the `.rst` extension on purpose. This
 * test scans `.php` files too, so spelling them in full here makes it fail on
 * its own docblock — which is exactly what happened on the first run. Do not
 * "complete" them.
 *
 * Both were wrong in a way a spell-check would not have caught. ADR-001 is
 * *Provider Abstraction Layer*; the record the sentence wanted was ADR-013. So
 * this test asserts existence only — that the file is there — and deliberately
 * does not try to judge whether the record says what the citing sentence
 * claims. Nothing mechanical can do that.
 *
 * It is the enforceable part of a larger problem. Issue #793 is about ADRs
 * citing code by line number and those citations rotting silently; this test
 * does not address that at all, because a line number that has drifted still
 * points at a line that exists.
 */
#[CoversNothing]
final class AdrReferenceIntegrityTest extends AbstractUnitTestCase
{
    /**
     * A concrete record filename: three digits and a name.
     *
     * Anchored on the digits on purpose, so the format template `AGENTS.md`
     * documents for new records — `Adr<N>Description.rst` — is not read as a
     * reference to a file called that.
     */
    private const REFERENCE_PATTERN = '/\bAdr\d{3}[A-Za-z0-9]*\.rst\b/';

    /** Directories that hold no repository content of ours. */
    private const SKIPPED_DIRECTORIES = ['.Build', '.git', 'node_modules', 'vendor', '__pycache__'];

    /** Where a reference can be written. Binary and generated formats are not scanned. */
    private const SCANNED_EXTENSIONS = ['md', 'rst', 'json', 'php', 'yml', 'yaml', 'neon', 'txt', 'xml'];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    #[Test]
    public function everyAdrNamedByFilenameExists(): void
    {
        $adrDir  = $this->repoRoot() . '/Documentation/Adr';
        $missing = [];

        foreach ($this->scannableFiles() as $relative) {
            $contents = file_get_contents($this->repoRoot() . '/' . $relative);
            self::assertIsString($contents, $relative . ' could not be read.');

            if (preg_match_all(self::REFERENCE_PATTERN, $contents, $matches) === 0) {
                continue;
            }

            foreach (array_unique($matches[0]) as $basename) {
                if (!is_file($adrDir . '/' . $basename)) {
                    $missing[] = sprintf('%s references %s, which does not exist', $relative, $basename);
                }
            }
        }

        sort($missing);

        self::assertSame([], $missing, sprintf(
            "These ADR references do not resolve:\n\n  %s\n\n"
            . "Open Documentation/Adr and use the real filename. Check that the record you land on is\n"
            . "the one the sentence means — the two references this test was written for were both the\n"
            . 'wrong record, not a misspelling of the right one.',
            implode("\n  ", $missing),
        ));
    }

    /**
     * @return list<string> repo-relative paths
     */
    private function scannableFiles(): array
    {
        $found    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->repoRoot(), FilesystemIterator::SKIP_DOTS),
                static fn(SplFileInfo $file): bool => !$file->isDir()
                    || !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true),
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            // The CLAUDE.md / GEMINI.md aliases are symlinks to the AGENTS.md
            // beside them. Scanning them too reports one bad reference three
            // times, which costs the reader two lookups to find one edit.
            if ($file->isLink()) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), self::SCANNED_EXTENSIONS, true)) {
                continue;
            }

            $found[] = substr($file->getPathname(), strlen($this->repoRoot()) + 1);
        }

        sort($found);

        return $found;
    }
}
