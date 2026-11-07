#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse an AGENTS.md without its GEMINI.md sibling, and any CLAUDE.md.
 *
 * AGENTS.md is the one instruction file per scope. Claude Code reads it
 * directly (v2.1.277 and later, https://code.claude.com/docs/en/memory#agents-md),
 * but only where no CLAUDE.md exists: a CLAUDE.md in a directory makes Claude
 * Code read that file instead of the AGENTS.md beside it. So a CLAUDE.md is
 * refused everywhere — a symlink, a copy or an `@AGENTS.md` import alike —
 * because each would put the project back on the indirection this check
 * removed.
 *
 * Gemini CLI reads GEMINI.md by default and AGENTS.md only when
 * `context.fileName` is configured (https://geminicli.com/docs/cli/gemini-md/),
 * so every scoped AGENTS.md keeps a GEMINI.md beside it. It is a symlink to
 * AGENTS.md and not a copy on purpose: a copy is a second source that drifts
 * silently, and nothing here would notice — the two files simply disagree, and
 * which one an agent believes depends on its vendor.
 *
 * ONE exemption from the GEMINI.md rule, named rather than pattern-matched:
 * `Documentation/`. The TYPO3 docs renderer walks that tree through Flysystem,
 * whose local adapter throws `SymbolicLinkEncountered` on ANY symlink it
 * lists — so adding the alias there does not degrade the render, it aborts it:
 *
 *   [League\Flysystem\SymbolicLinkEncountered]
 *   Unsupported symbolic link encountered at location /project/Documentation/CLAUDE.md
 *
 * That was measured, not predicted: the aliases were added, and all three
 * `Render Documentation` jobs failed on it. Excluding the path is not ours to
 * do — the render is a shared `netresearch/*` reusable workflow serving every
 * caller — and a copy is refused above for the drift it invites. So the scope
 * stays reachable for Gemini CLI the way it already was: the root `AGENTS.md`,
 * which does have the alias, names `Documentation/AGENTS.md` in its index
 * table. The exemption covers the GEMINI.md requirement only; a CLAUDE.md is
 * refused there as everywhere else.
 *
 * Runs in the shared workflow's `repo-checks` job via `ci:test:repo`, which is
 * also what pre-commit runs, so the local and CI halves are the same command.
 * The first argument, when given, replaces the repository root; the unit test
 * points the check at a throwaway tree that way.
 */

/** Required beside every AGENTS.md, as a symlink to it. */
const ALIASES = ['GEMINI.md'];

/** Refused anywhere in the tree: Claude Code would read it instead of AGENTS.md. */
const FORBIDDEN = ['CLAUDE.md'];

/**
 * Directories that never hold source we govern.
 *
 * `.specify` is vendored, not written here: `specify preset add` copies an
 * installed preset in whole, including that preset repository's own AGENTS.md,
 * which documents the preset rather than any scope of this extension. Two
 * reasons it is skipped rather than exempted. The aliases genuinely cannot be
 * there — Spec Kit's `safe_extract_archive` rejects an archive containing a
 * symlink, so the presets ship without them on purpose. And anything placed
 * there by hand is destroyed by the next `preset add`, which rewrites the
 * directory.
 *
 * `.claude` is gitignored and belongs to whoever works in the clone; a
 * personal `.claude/CLAUDE.md` there is that person's choice, and refusing it
 * would fail their pre-commit hook over a file the repository never sees.
 */
const SKIP = ['.git', '.Build', 'vendor', 'node_modules', 'var', 'public', '.specify', '.claude'];

/** Directories where the GEMINI.md symlink cannot exist, and why. */
const EXEMPT = [
    // Flysystem aborts the docs render on any symlink under this tree.
    'Documentation',
];

// The repository root, or the directory given as the first argument.
$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');

/**
 * Every AGENTS.md directory and every forbidden file below $root.
 *
 * The forbidden names are matched by filename alone: a dangling symlink is
 * not a file to `isFile()`, and Claude Code would still try to read it.
 *
 * @return array{list<string>, list<string>} AGENTS.md directories, forbidden paths
 */
function walk(string $root): array
{
    $directories = [];
    $forbidden   = [];
    $iterator    = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), SKIP, true),
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if (in_array($file->getFilename(), FORBIDDEN, true)) {
            $forbidden[] = $file->getPathname();
        } elseif ($file->isFile() && $file->getFilename() === 'AGENTS.md') {
            $directories[] = dirname($file->getPathname());
        }
    }

    sort($directories);
    sort($forbidden);

    return [$directories, $forbidden];
}

function relative(string $root, string $path): string
{
    return ltrim(substr($path, strlen($root)), '/') ?: '.';
}

[$directories, $forbidden] = walk($root);

$problems = [];

foreach ($directories as $directory) {
    $relative = relative($root, $directory);

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

foreach ($forbidden as $path) {
    $problems[] = sprintf('  %s: must not exist', relative($root, $path));
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "These agent instruction files are missing, wrong or not allowed:\n\n%s\n\n"
    . "Every directory with an AGENTS.md needs GEMINI.md beside it, a symlink to\n"
    . "AGENTS.md: Gemini CLI reads GEMINI.md by default, so a missing alias hides\n"
    . "that scope's rules from it without anything failing. Create it relative,\n"
    . "so it survives a clone and a worktree:\n\n"
    . "  cd <directory> && ln -s AGENTS.md GEMINI.md\n\n"
    . "Do not copy the file instead. A copy is a second source that drifts in\n"
    . "silence, and this check cannot tell you which of the two is current.\n\n"
    . "Do not add a CLAUDE.md, in any form. Claude Code reads AGENTS.md itself, and\n"
    . "a CLAUDE.md in a directory makes it read that file instead of the AGENTS.md\n"
    . "beside it. Delete the file; put the instruction into AGENTS.md.\n\n"
    . "If a tree genuinely cannot carry a symlink — the docs render is the one\n"
    . "known case — add the directory to EXEMPT in this script together with the\n"
    . "reason, rather than dropping the alias silently.\n",
    implode("\n", $problems),
));

exit(1);
