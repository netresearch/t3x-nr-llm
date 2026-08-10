#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse a CHANGELOG whose `## [Unreleased]` section repeats itself.
 *
 * Merging `main` into a series of sibling branches duplicates append-only
 * sections whenever the conflict resolution assumes "ours holds only my
 * additions". That is true for the first merge of a series and false for every
 * later one, and the result has no conflict markers and a plausible diff — on
 * 2026-08-10 ten bullets ended up standing three and four times over, and two
 * pull requests carried it to `main` before anyone counted.
 *
 * Two things are checked, both cheap and both deterministic:
 *
 *   1. no heading appears twice inside `[Unreleased]` (a second `### Changed`
 *      splits the section so the duplicate sits forty lines from the original),
 *   2. no top-level bullet's first line appears twice.
 *
 * Only `[Unreleased]` is examined. Released sections are history and may
 * legitimately repeat a line that was true in two releases.
 */

$path = $argv[1] ?? dirname(__DIR__, 2) . '/CHANGELOG.md';

if (!is_readable($path)) {
    fwrite(STDERR, sprintf("check-changelog-unreleased: cannot read %s\n", $path));
    exit(2);
}

$text = (string)file_get_contents($path);

$start = strpos($text, '## [Unreleased]');
if ($start === false) {
    // A CHANGELOG without the section is not this check's business.
    exit(0);
}

$bodyStart = $start + strlen('## [Unreleased]');
$rest      = substr($text, $bodyStart);
$section   = preg_match('/^## \[/m', $rest, $m, PREG_OFFSET_CAPTURE) === 1
    ? substr($rest, 0, $m[0][1])
    : $rest;

$lines = explode("\n", $section);

/** @var array<string, int> $headings */
$headings = [];
/** @var array<string, int> $bullets */
$bullets = [];
foreach ($lines as $line) {
    if (str_starts_with($line, '### ')) {
        $key            = trim($line);
        $headings[$key] = ($headings[$key] ?? 0) + 1;

        continue;
    }

    // Top-level bullets only: a nested `  - ` may legitimately repeat.
    if (str_starts_with($line, '- ')) {
        $key           = trim($line);
        $bullets[$key] = ($bullets[$key] ?? 0) + 1;
    }
}

$problems = [];
foreach ($headings as $key => $count) {
    if ($count > 1) {
        $problems[] = sprintf('  %dx heading  %s', $count, $key);
    }
}

foreach ($bullets as $key => $count) {
    if ($count > 1) {
        $problems[] = sprintf('  %dx bullet   %s', $count, mb_substr($key, 0, 90));
    }
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "The [Unreleased] section of %s repeats itself:\n\n%s\n\n"
    . "This is what merging `main` into a series of sibling branches produces when the\n"
    . "conflict resolution assumes \"ours\" holds only your own additions — true for the\n"
    . "first merge, false for every later one. Rebuild the section instead of merging it:\n"
    . "take the incoming version verbatim and re-insert your own block under the matching\n"
    . "heading.\n",
    $path,
    implode("\n", $problems),
));

exit(1);
