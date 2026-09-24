#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse a CHANGELOG whose released sections differ from what was released.
 *
 * A section `## [X.Y.Z]` describes what the tag vX.Y.Z contains, so after the
 * release its text is fixed: it must be byte-identical to the same section in
 * `git show vX.Y.Z:CHANGELOG.md`. The sibling check-changelog-unreleased.php
 * reads only `[Unreleased]` and cannot see an entry that lands in a released
 * section. That happens without anybody editing the section: the merge queue
 * combined #970 with the release commit of 0.37.0, which had emptied
 * `[Unreleased]`, and git placed #970's entry inside `[0.37.0]` — a change
 * 0.37.0 does not contain, with every check green (corrected in #973).
 *
 * Tags: CI checks out with depth 1 and no tags. A tag missing in the checkout
 * is fetched once from origin (`git fetch --depth=1`). A section whose tag
 * still cannot be read fails the check, except the topmost released section:
 * the release PR adds that one before the tag is pushed.
 *
 * KNOWN_DEVIATIONS lists the sections that differ from their tag on purpose.
 * Each is pinned to the SHA-256 of its current text, so a further change to
 * one of them fails like any other. To correct a released section on purpose,
 * edit it, add or update its entry here, and say why.
 *
 * The link-reference block at the end of the file (`[x.y.z]: https://…`) is
 * not part of any section, because every release rewrites it.
 *
 * Usage: check-changelog-released-sections.php [CHANGELOG path] [git work tree]
 */

/** @var array<string, array{sha256: string, reason: string}> */
const KNOWN_DEVIATIONS = [
    '0.32.0' => [
        'sha256' => '80b01815db9af94d05cf8a1aad710cd6d1a8bc8fcf454e3283bb6fd399fdbead',
        'reason' => 'the ADR-179 entry (dropped forced sources) moved here from [0.31.0]; it first shipped in v0.32.0',
    ],
    '0.31.0' => [
        'sha256' => '7899115d2e97a85d227e9e5f9647e2a94032bfb985079e1577eacab5bd578d01',
        'reason' => 'the #815 entry (enum backing values in the api-surface snapshot) moved here from [0.30.0]; it first shipped in v0.31.0',
    ],
    '0.28.0' => [
        'sha256' => '47b93d52177d4904728d327b668d4f515211389b7f85f1dd04895ae859f6dfcf',
        'reason' => 'one blank line at the end of the section was removed after the release',
    ],
    '0.27.0' => [
        'sha256' => '378d3da02490dffbe67b5013925c4d86baedc0e50a11500c5005cf1797a62fb9',
        'reason' => 'de-duplicated and sorted into the right headings after the release (97306715)',
    ],
    '0.25.0' => [
        'sha256' => '3efbe1ec23629cfaa741ba2c6c06c396447f6f066fe7f679f4e2528d82869fa1',
        'reason' => 'the CHANGELOG at tag v0.25.0 has no [0.25.0] section; it was written after the tag',
    ],
    '0.19.1' => [
        'sha256' => '10b15dd00649b920989784ccdb82fda5019205463a1bbd1173b45a4310ac9f85',
        'reason' => 'never tagged: the release run was cancelled, and the fixes first shipped in 0.20.0',
    ],
    '0.17.0' => [
        'sha256' => '0ecf5728b9ae3330d96f4d7f1b777c2dbdb55175a3d9d0080ef5ef873883a768',
        'reason' => 'backfilled with #341 and #342 after the release (6ee06332)',
    ],
    '0.7.0' => [
        'sha256' => '247b22af35d3f2e66460010520ad2f988483fd3d533a5ca44de1f188392b4f35',
        'reason' => 'tag v0.7.0 carries no CHANGELOG.md',
    ],
];

$root = $argv[2] ?? dirname(__DIR__, 2);
$path = $argv[1] ?? $root . '/CHANGELOG.md';

if (!is_readable($path)) {
    fwrite(STDERR, sprintf("check-changelog-released-sections: cannot read %s\n", $path));
    exit(2);
}

/**
 * Split a CHANGELOG into its released sections, keyed by version.
 *
 * A section runs from its `## [X.Y.Z]` heading to the next `## [` heading, or
 * to the link-reference block, or to the end of the text.
 *
 * @return array<string, string>
 */
function releasedSections(string $text): array
{
    if (preg_match('/^\[[^\]]+\]: \S+/m', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
        $text = substr($text, 0, $m[0][1]);
    }

    $parts = preg_split('/^(?=## \[)/m', $text) ?: [];
    $sections = [];
    foreach ($parts as $part) {
        if (preg_match('/^## \[(\d[^\]]*)\]/', $part, $h) === 1) {
            $sections[$h[1]] = $part;
        }
    }

    return $sections;
}

/**
 * Run git in the work tree; return stdout, or null when git fails.
 *
 * @param list<string> $args
 */
function git(string $root, array $args): ?string
{
    $cmd = array_merge(['git', '-C', $root], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    $out = (string)stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0 ? $out : null;
}

function tagChangelog(string $root, string $version): ?string
{
    return git($root, ['show', 'refs/tags/v' . $version . ':CHANGELOG.md']);
}

$current = releasedSections((string)file_get_contents($path));
if ($current === []) {
    exit(0);
}

$topmost = array_key_first($current);

$missing = [];
foreach (array_keys($current) as $version) {
    if (!isset(KNOWN_DEVIATIONS[$version]) && tagChangelog($root, $version) === null) {
        $missing[] = $version;
    }
}

if ($missing !== []) {
    // One fetch for all of them; a shallow CI checkout carries no tags.
    $refspecs = array_map(static fn(string $v): string => '+refs/tags/v' . $v . ':refs/tags/v' . $v, $missing);
    git($root, array_merge(['fetch', '--quiet', '--depth=1', '--no-tags', 'origin'], $refspecs));
}

$problems = [];
$checked = 0;
$pinned = 0;
foreach ($current as $version => $section) {
    if (isset(KNOWN_DEVIATIONS[$version])) {
        if (hash('sha256', $section) !== KNOWN_DEVIATIONS[$version]['sha256']) {
            $problems[] = sprintf(
                '  [%s]  changed since it was pinned as a known deviation (%s)',
                $version,
                KNOWN_DEVIATIONS[$version]['reason'],
            );
        } else {
            $pinned++;
        }

        continue;
    }

    $released = tagChangelog($root, $version);
    if ($released === null) {
        if ($version !== $topmost) {
            $problems[] = sprintf('  [%s]  tag v%s cannot be read, so the section cannot be verified', $version, $version);
        }

        continue;
    }

    $checked++;
    $atTag = releasedSections($released)[$version] ?? null;
    if ($atTag === null) {
        $problems[] = sprintf('  [%s]  tag v%s has no such section in its CHANGELOG.md', $version, $version);
    } elseif ($atTag !== $section) {
        $problems[] = sprintf('  [%s]  differs from the section at tag v%s', $version, $version);
    }
}

if ($problems === []) {
    fwrite(STDOUT, sprintf(
        "check-changelog-released-sections: %d released section(s) match their tags, %d pinned known deviation(s) unchanged\n",
        $checked,
        $pinned,
    ));
    exit(0);
}

fwrite(STDERR, sprintf(
    "Released sections of %s do not match what was released:\n\n%s\n\n"
    . "A released section must stay as the tag has it. An entry that sits there now\n"
    . "usually arrived through a merge or the merge queue combining a PR with a release\n"
    . "commit. Move it back under [Unreleased], and compare the section with\n"
    . "`git show vX.Y.Z:CHANGELOG.md`.\n",
    $path,
    implode("\n", $problems),
));

exit(1);
