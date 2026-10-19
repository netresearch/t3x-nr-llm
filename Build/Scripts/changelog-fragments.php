#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Check and collect CHANGELOG fragments in `changelog.d/`.
 *
 * A pull request writes its CHANGELOG entry into its own file
 * `changelog.d/<branch-slug>.<section>.md` instead of editing the shared
 * `## [Unreleased]` section. Every PR that edited that section conflicted with
 * every other open PR as soon as one of them merged; in October 2026, 56 of 64
 * conflicting PRs conflicted in CHANGELOG.md and nowhere else, and the merge
 * queue could take them only one at a time.
 *
 *   check    Refuse a malformed fragment, and refuse fragments while the
 *            topmost released section has no tag yet: either the release
 *            commit skipped `collect`, and those notes would not ship, or the
 *            release is merged and its tag is not pushed yet.
 *   collect  Append every fragment under its heading in `## [Unreleased]` and
 *            delete the fragment. The release commit runs this before it
 *            renames `[Unreleased]`. Running it with no fragments changes
 *            nothing.
 *
 * The file names follow towncrier's `<name>.<type>.md` scheme. towncrier
 * renders a complete versioned section at a marker; it has no documented mode
 * that appends to a hand-maintained `[Unreleased]` section, which this
 * repository keeps for Renovate and for PRs that predate the fragments.
 *
 * Usage: changelog-fragments.php check|collect [repository root]
 */

/** Fragment type => CHANGELOG heading, in the order headings appear. */
const SECTIONS = [
    'upgrading'  => null,
    'added'      => '### Added',
    'changed'    => '### Changed',
    'deprecated' => '### Deprecated',
    'removed'    => '### Removed',
    'fixed'      => '### Fixed',
    'security'   => '### Security',
];

const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*\.(upgrading|added|changed|deprecated|removed|fixed|security)\.md$/';

$mode = $argv[1] ?? '';
$root = $argv[2] ?? dirname(__DIR__, 2);
if (!in_array($mode, ['check', 'collect'], true)) {
    fwrite(STDERR, "usage: changelog-fragments.php check|collect [repository root]\n");
    exit(2);
}

$dir = $root . '/changelog.d';
$changelogPath = $root . '/CHANGELOG.md';
if (!is_readable($changelogPath)) {
    fwrite(STDERR, sprintf("changelog-fragments: cannot read %s\n", $changelogPath));
    exit(2);
}

/**
 * Read the fragments, sorted by file name.
 *
 * @param list<string> $problems
 *
 * @return list<array{file: string, type: string, text: string}>
 */
function readFragments(string $dir, array &$problems): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $names = scandir($dir) ?: [];
    sort($names, SORT_STRING);
    $fragments = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..' || $name === 'README.md') {
            continue;
        }

        $path = $dir . '/' . $name;
        if (preg_match(NAME_PATTERN, $name, $m) !== 1 || !is_file($path)) {
            $problems[] = sprintf('  %s  is not named <branch-slug>.<%s>.md', $name, implode('|', array_keys(SECTIONS)));

            continue;
        }

        $text = trim(str_replace("\r\n", "\n", (string)file_get_contents($path)));
        if (!str_starts_with($text, '- ')) {
            $problems[] = sprintf('  %s  must start with a top-level bullet ("- ")', $name);

            continue;
        }

        if (preg_match('/^#/m', $text) === 1) {
            $problems[] = sprintf('  %s  must not contain a heading; the file name selects the heading', $name);

            continue;
        }

        $fragments[] = ['file' => $path, 'type' => $m[1], 'text' => $text];
    }

    return $fragments;
}

/**
 * The version of the topmost released section, or null when there is none.
 */
function topmostReleased(string $changelog): ?string
{
    return preg_match('/^## \[(\d[^\]]*)\]/m', $changelog, $m) === 1 ? $m[1] : null;
}

/**
 * Whether tag v$version exists, locally or on origin.
 *
 * A shallow CI checkout carries no tags, so origin is asked with ls-remote. It
 * fetches nothing, so it cannot turn a full clone into a shallow one. Without
 * an origin there is nothing else to ask. A failing ls-remote stops the check
 * with its own message, because reporting it as a missing tag would name the
 * wrong cause.
 */
function tagExists(string $root, string $version): bool
{
    $ref = 'refs/tags/v' . $version;
    if (run(['git', '-C', $root, 'rev-parse', '--quiet', '--verify', $ref])) {
        return true;
    }

    if (!run(['git', '-C', $root, 'remote', 'get-url', 'origin'])) {
        return false;
    }

    $listed = '';
    if (!run(['git', '-C', $root, 'ls-remote', '--tags', 'origin', $ref], $listed)) {
        fwrite(STDERR, sprintf("changelog-fragments: cannot ask origin whether tag v%s exists. Check the remote and run the check again.\n", $version));
        exit(2);
    }

    return trim($listed) !== '';
}

/**
 * @param list<string> $command
 */
function run(array $command, string &$stdout = ''): bool
{
    // nosemgrep: netresearch.php.lang.security.exec-use.exec-use - argv array, no shell; every caller passes literal git arguments plus this repository's own root and a version read from its CHANGELOG
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return false;
    }

    $stdout = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0;
}

/**
 * Append the fragments to the `[Unreleased]` section of $changelog.
 *
 * The section is split into blocks at its `### ` headings; a fragment is
 * appended to the end of the block with its heading, and a missing heading is
 * inserted before the first heading that follows it in SECTIONS order.
 *
 * @param list<array{file: string, type: string, text: string}> $fragments
 */
function collect(string $changelog, array $fragments): ?string
{
    $start = strpos($changelog, '## [Unreleased]');
    if ($start === false) {
        return null;
    }

    $rest = substr($changelog, $start);
    $end = preg_match('/^(## \[(?!Unreleased\])|\[[^\]]+\]: \S)/m', $rest, $m, PREG_OFFSET_CAPTURE) === 1
        ? $m[0][1]
        : strlen($rest);
    $section = substr($rest, 0, $end);

    // Block 0 is the heading line plus anything above the first `### `.
    $blocks = preg_split('/^(?=### )/m', $section) ?: [$section];
    // Trimmed, so a CRLF checkout or a trailing space still matches its heading.
    $heads = array_map(static fn(string $b): string => rtrim(strtok($b, "\n") ?: ''), $blocks);

    $released = topmostReleased($changelog);
    $upgrading = '### Upgrading';
    foreach ($heads as $head) {
        if (str_starts_with($head, '### Upgrading')) {
            $upgrading = $head;
        }
    }

    if ($upgrading === '### Upgrading' && $released !== null && preg_match('/^(\d+\.\d+)/', $released, $v) === 1) {
        $upgrading = '### Upgrading from ' . $v[1];
    }

    $headingOf = static fn(string $type): string => SECTIONS[$type] ?? $upgrading;
    $rank = static function (string $head) use ($headingOf): ?int {
        $i = 0;
        foreach (array_keys(SECTIONS) as $type) {
            if ($head === $headingOf($type) || ($type === 'upgrading' && str_starts_with($head, '### Upgrading'))) {
                return $i;
            }

            $i++;
        }

        return null;
    };

    foreach ($fragments as $fragment) {
        $heading = $headingOf($fragment['type']);
        $index = array_search($heading, $heads, true);
        if ($index === false) {
            $want = (int)$rank($heading);
            $index = count($blocks);
            foreach ($heads as $i => $head) {
                $have = $rank($head);
                if ($i > 0 && $have !== null && $have > $want) {
                    $index = $i;

                    break;
                }
            }

            $blocks[$index - 1] = rtrim($blocks[$index - 1], "\r\n") . "\n\n";
            array_splice($blocks, $index, 0, [$heading . "\n"]);
            array_splice($heads, $index, 0, [$heading]);
        }

        $blocks[$index] = rtrim($blocks[$index], "\r\n") . "\n\n" . $fragment['text'] . "\n\n";
    }

    return substr($changelog, 0, $start) . implode('', $blocks) . substr($rest, $end);
}

$problems = [];
$fragments = readFragments($dir, $problems);
$changelog = (string)file_get_contents($changelogPath);

if ($mode === 'check') {
    $released = topmostReleased($changelog);
    if ($fragments !== [] && $released !== null && !tagExists($root, $released)) {
        $problems[] = sprintf(
            '  [%s] has no tag v%s yet, and %d fragment(s) are left. In the release commit, run'
            . ' `composer changelog:collect` before renaming [Unreleased], or the release ships without'
            . ' their notes. If the release is already merged, push tag v%s and run the check again.',
            $released,
            $released,
            count($fragments),
            $released,
        );
    }
}

if ($problems !== []) {
    fwrite(STDERR, sprintf(
        "changelog.d/ is not ready:\n\n%s\n\nSee changelog.d/README.md for the format.\n",
        implode("\n", $problems),
    ));
    exit(1);
}

if ($mode === 'check') {
    fwrite(STDOUT, sprintf("changelog-fragments: %d fragment(s) ok\n", count($fragments)));
    exit(0);
}

if ($fragments === []) {
    fwrite(STDOUT, "changelog-fragments: nothing to collect\n");
    exit(0);
}

$collected = collect($changelog, $fragments);
if ($collected === null) {
    fwrite(STDERR, sprintf("changelog-fragments: %s has no ## [Unreleased] section to collect into\n", $changelogPath));
    exit(1);
}

// The fragments are deleted only after the changelog holds their text; a
// failed or short write keeps them, so nothing is lost.
if (file_put_contents($changelogPath, $collected) !== strlen($collected)) {
    fwrite(STDERR, sprintf("changelog-fragments: cannot write %s; the fragments are kept.\n", $changelogPath));
    exit(2);
}
foreach ($fragments as $fragment) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use - a fragment file this script just read from the repository's own changelog.d/ and validated by name
    unlink($fragment['file']);
}

fwrite(STDOUT, sprintf("changelog-fragments: collected %d fragment(s) into [Unreleased]\n", count($fragments)));
exit(0);
