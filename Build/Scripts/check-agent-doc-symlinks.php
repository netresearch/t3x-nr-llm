#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse an AGENTS.md whose CLAUDE.md / GEMINI.md siblings are missing.
 *
 * Agents read whichever filename their vendor taught them, so every scoped
 * AGENTS.md needs the two aliases beside it or that scope's rules are invisible
 * to whoever asks for the other name. The aliases are symlinks to AGENTS.md and
 * not copies on purpose: a copy is a second source that drifts silently, and
 * nothing here would notice — the two files simply disagree, and which one an
 * agent believes depends on its vendor.
 *
 * ONE exemption, named rather than pattern-matched: `Documentation/`. The TYPO3
 * docs renderer walks that tree through Flysystem, whose local adapter throws
 * `SymbolicLinkEncountered` on ANY symlink it lists — so adding the pair there
 * does not degrade the render, it aborts it:
 *
 *   [League\Flysystem\SymbolicLinkEncountered]
 *   Unsupported symbolic link encountered at location /project/Documentation/CLAUDE.md
 *
 * That was measured, not predicted: the aliases were added, and all three
 * `Render Documentation` jobs failed on it. Excluding the path is not ours to
 * do — the render is a shared `netresearch/*` reusable workflow serving every
 * caller — and a copy is refused above for the drift it invites. So the scope
 * stays reachable the way it already was: the root `AGENTS.md`, which does have
 * the aliases, names `Documentation/AGENTS.md` in its index table, so a reader
 * who opened CLAUDE.md is still told the file exists and where.
 *
 * Runs in the shared workflow's `repo-checks` job via `ci:test:repo`, which is
 * also what pre-commit runs, so the local and CI halves are the same command.
 */

const ALIASES = ['CLAUDE.md', 'GEMINI.md'];

/** Directories that never hold source we govern. */
const SKIP = ['.git', '.Build', 'vendor', 'node_modules', 'var', 'public'];

/** Directories where the pair cannot exist, and why. */
const EXEMPT = [
    // Flysystem aborts the docs render on any symlink under this tree.
    'Documentation',
];

$root = dirname(__DIR__, 2);

/**
 * @return list<string> every directory below $root holding an AGENTS.md
 */
function agentsDirectories(string $root): array
{
    $found    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), SKIP, true),
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() === 'AGENTS.md') {
            $found[] = dirname($file->getPathname());
        }
    }

    sort($found);

    return $found;
}

$problems = [];

foreach (agentsDirectories($root) as $directory) {
    $relative = ltrim(str_replace($root, '', $directory), '/') ?: '.';

    if (in_array($relative, EXEMPT, true)) {
        continue;
    }

    foreach (ALIASES as $alias) {
        $path = $directory . '/' . $alias;

        if (!is_link($path)) {
            $problems[] = sprintf(
                '  %s/%s: %s',
                $relative,
                $alias,
                file_exists($path) ? 'is a regular file, not a symlink' : 'missing',
            );

            continue;
        }

        $target = readlink($path);

        if ($target !== 'AGENTS.md') {
            $problems[] = sprintf('  %s/%s: points at "%s", expected "AGENTS.md"', $relative, $alias, $target);
        }
    }
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "These agent instruction aliases are missing or wrong:\n\n%s\n\n"
    . "Every directory with an AGENTS.md needs CLAUDE.md and GEMINI.md beside it,\n"
    . "each a symlink to AGENTS.md. Agents read whichever filename their vendor\n"
    . "taught them, so a missing alias hides that scope's rules from half the\n"
    . "readers without anything failing.\n\n"
    . "Create them relative, so they survive a clone and a worktree:\n\n"
    . "  cd <directory> && ln -s AGENTS.md CLAUDE.md && ln -s AGENTS.md GEMINI.md\n\n"
    . "Do not copy the file instead. A copy is a second source that drifts in\n"
    . "silence, and this check cannot tell you which of the two is current.\n\n"
    . "If a tree genuinely cannot carry a symlink — the docs render is the one\n"
    . "known case — add the directory to EXEMPT in this script together with the\n"
    . "reason, rather than dropping the alias silently.\n",
    implode("\n", $problems),
));

exit(1);
