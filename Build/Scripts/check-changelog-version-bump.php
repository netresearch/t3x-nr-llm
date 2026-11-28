#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse a 0.x release whose version number does not match its breaking entries.
 *
 * Under 0.x, Composer's `^0.54` admits only 0.54.*, so raising the middle
 * number forces every dependent extension to widen its constraint and release.
 * 0.45.0 to 0.55.0 raised it eleven times without a breaking entry. The rule
 * (Documentation/Api/Stability.rst, "Versioning during 0.x"):
 *
 *   - a release that raises the middle number carries at least one breaking
 *     entry, and raises it by exactly one to a last number of 0 (0.N.y →
 *     0.N+1.0),
 *   - a release that keeps the middle number carries none.
 *
 * A release is compared with the highest plain version below it, wherever that
 * section stands in the file. Every `## ` heading, older ones included, must be
 * `## [Unreleased]` or `## [N.N.N]` (no `v`, no pre-release suffix, brackets
 * required): any other heading would drop out of the comparison and switch it
 * off.
 *
 * A breaking entry is a top-level bullet that opens with `- **BREAKING`,
 * followed by anything but a letter, digit or underscore. One strict rule keeps
 * every variant out: in a judged section, in `[Unreleased]` and in a
 * changelog.d/ fragment the word "breaking" (any case, outside inline code and
 * fenced code) may stand only there, once, as the marker. Any other occurrence
 * is refused, prose such as "not a breaking change" included; the author
 * rewords it or quotes the word as inline code. An earlier net of lookalike
 * patterns missed a new variant in two review rounds in a row.
 *
 * What is breaking is decided by the author: a breaking diff of
 * Tests/Unit/Api/api-surface.txt (a new method on an @api interface or abstract
 * class and a new case of an @api enum included), or a behaviour change
 * consumers have to handle. This script checks that the version number agrees with the CHANGELOG.
 *
 * Only releases after RULE_SINCE are judged. Released sections are frozen by
 * check-changelog-released-sections.php, and the older ones break the rule.
 *
 * Usage: check-changelog-version-bump.php [CHANGELOG path]
 *        (changelog.d/ is read from the CHANGELOG's directory)
 */

require_once __DIR__ . '/changelog-sections.php';

/** The last release before the rule; releases after it are checked. */
const RULE_SINCE = '0.55.1';

const MARKER = '- **BREAKING';

/** The marker, followed by anything but a letter, digit or underscore. */
const MARKER_LINE = '/^- \*\*BREAKING(?![A-Za-z0-9_])/';

const PLAIN_VERSION = '/^(\d+)\.(\d+)\.(\d+)$/';

$path = $argv[1] ?? dirname(__DIR__, 2) . '/CHANGELOG.md';

if (!is_readable($path)) {
    fwrite(STDERR, sprintf("check-changelog-version-bump: cannot read %s\n", $path));
    exit(2);
}

/**
 * The lines of $text outside fenced code blocks (``` or ~~~), keyed by their
 * 0-based index in $text. An example of the format inside a fence is neither
 * a marker nor a lookalike.
 *
 * @return array<int, string>
 */
function proseLines(string $text): array
{
    $prose = [];
    $fence = null;
    foreach (explode("\n", $text) as $i => $line) {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m) === 1) {
            if ($fence === null) {
                $fence = $m[1][0];
            } elseif ($m[1][0] === $fence) {
                $fence = null;
            }

            continue;
        }

        if ($fence === null) {
            $prose[$i] = $line;
        }
    }

    return $prose;
}

/**
 * The prose lines of $text with inline code blanked, keyed like proseLines().
 *
 * A code span may cross a line break, so the lines of each bullet or
 * paragraph are joined before the spans are blanked; a blanked span keeps its
 * line breaks, so every line keeps its number.
 *
 * @return array<int, string>
 */
function blankedProseLines(string $text): array
{
    $blocks  = [];
    $current = [];
    $last    = null;
    foreach (proseLines($text) as $i => $line) {
        $startsBlock = trim($line) === ''
            || preg_match('/^\s*(?:[-*+]|\d+\.)\s|^#/', $line) === 1
            || ($last !== null && $i !== $last + 1);
        if ($startsBlock && $current !== []) {
            $blocks[] = $current;
            $current  = [];
        }

        if (trim($line) !== '') {
            $current[$i] = $line;
        }

        $last = $i;
    }

    if ($current !== []) {
        $blocks[] = $current;
    }

    $blanked = [];
    foreach ($blocks as $block) {
        $joined = (string) preg_replace_callback(
            '/`[^`]*`/',
            static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            implode("\n", $block),
        );
        $lines = explode("\n", $joined);
        foreach (array_keys($block) as $n => $i) {
            $blanked[$i] = $lines[$n];
        }
    }

    ksort($blanked);

    return $blanked;
}

/**
 * Line numbers (1-based, counted from $firstLine) of every line where the word
 * "breaking" stands anywhere but as the one marker of a marker line.
 *
 * Inline code is blanked first, so a text that quotes the word as
 * `**BREAKING` is allowed. The opening bold run of a bullet is read before
 * that: `- **`BREAKING`:**` puts the word in bold, but is not the marker.
 *
 * @return list<int>
 */
function lookalikeLines(string $text, int $firstLine = 1): array
{
    $raw   = proseLines($text);
    $lines = [];
    foreach (blankedProseLines($text) as $i => $prose) {
        $count = preg_match_all('/breaking/i', $prose, $hits, PREG_OFFSET_CAPTURE);

        $boldIsMarker = true;
        if (preg_match('/^- \*\*(.*?)\*\*/', $raw[$i], $bold) === 1 && preg_match('/breaking/i', $bold[1]) === 1) {
            $boldIsMarker = preg_match(MARKER_LINE, $raw[$i]) === 1 && preg_match_all('/breaking/i', $bold[1]) === 1;
        }

        if ($count === 0 && $boldIsMarker) {
            continue;
        }

        $onlyTheMarker = $boldIsMarker && $count === 1 && $hits[0][0][1] === 4 && preg_match(MARKER_LINE, $prose) === 1;
        if (!$onlyTheMarker) {
            $lines[] = $firstLine + $i;
        }
    }

    return $lines;
}

function hasMarker(string $section): bool
{
    foreach (proseLines($section) as $line) {
        if (preg_match(MARKER_LINE, $line) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * 1-based line number of byte $offset in $text.
 */
function lineAt(string $text, int $offset): int
{
    return substr_count(substr($text, 0, $offset), "\n") + 1;
}

$text     = (string) file_get_contents($path);
$sections = releasedSections($text);
$problems = [];

$lookalike = static function (string $where, string $text, int $firstLine = 1) use (&$problems): void {
    foreach (lookalikeLines($text, $firstLine) as $line) {
        $problems[] = sprintf(
            '  %s  line %d: the word "breaking" is only allowed on a marker line: a top-level bullet that'
            . ' opens with `%s` (upper case, then no letter, digit or underscore), once — reword the'
            . ' line or quote the word as inline code',
            $where,
            $line,
            MARKER,
        );
    }
};

// [Unreleased] and the fragments: caught on the pull request, before a release.
// The section runs from its heading to the next `## [` heading or the
// trailing link-reference block, as in changelog-sections.php.
$start = strpos($text, '## [Unreleased]');
if ($start !== false) {
    $unreleased = substr($text, $start, max(0, linkBlockStart($text) - $start));
    if (preg_match('/^## \[(?!Unreleased)/m', $unreleased, $end, PREG_OFFSET_CAPTURE) === 1) {
        $unreleased = substr($unreleased, 0, $end[0][1]);
    }

    $lookalike('[Unreleased]', $unreleased, lineAt($text, $start));
}

$fragments     = dirname($path) . '/changelog.d';
$fragmentFiles = is_dir($fragments) ? glob($fragments . '/*.md') : [];
foreach ($fragmentFiles ?: [] as $fragment) {
    if (basename($fragment) !== 'README.md') {
        $lookalike('changelog.d/' . basename($fragment), (string) file_get_contents($fragment));
    }
}

// Every `## ` heading is `## [Unreleased]` or `## [N.N.N]`: releasedSections()
// reads only bracketed headings that start with a digit, so `## [v0.56.0]` or
// `## 0.56.0` would otherwise drop out of every check below.
foreach (proseLines($text) as $i => $line) {
    if (!str_starts_with($line, '## ')) {
        continue;
    }

    if (preg_match('/^## \[([^\]]*)\]/', $line, $heading) !== 1) {
        $problems[] = sprintf(
            '  `%s`  line %d: only `## [Unreleased]` and `## [N.N.N] …` headings are allowed; a heading'
            . ' without brackets is not read as a release',
            $line,
            $i + 1,
        );
    } elseif ($heading[1] !== 'Unreleased' && preg_match(PLAIN_VERSION, $heading[1]) !== 1) {
        $problems[] = sprintf(
            '  [%s]  line %d: is not a plain N.N.N version — a CHANGELOG section names a release, written'
            . ' without a `v` and without a pre-release suffix',
            $heading[1],
            $i + 1,
        );
    }
}

/** @var list<string> $plain */
$plain = array_values(array_filter(
    array_map('strval', array_keys($sections)),
    static fn (string $v): bool => preg_match(PLAIN_VERSION, $v) === 1,
));

$checked = 0;
foreach ($sections as $version => $section) {
    $version = (string) $version;
    if (version_compare($version, RULE_SINCE, '<=')) {
        continue;
    }

    if (preg_match(PLAIN_VERSION, $version, $n) !== 1) {
        continue; // reported with the headings above
    }

    if ($n[1] !== '0') {
        continue;
    }

    $earlier = array_filter($plain, static fn (string $v): bool => version_compare($v, $version, '<'));
    if ($earlier === []) {
        continue;
    }

    usort($earlier, 'version_compare');
    $previous = (string) end($earlier);
    if (preg_match(PLAIN_VERSION, $previous, $p) !== 1 || $p[1] !== '0') {
        continue;
    }

    ++$checked;
    $lookalike('[' . $version . ']', $section, lineAt($text, (int) strpos($text, '## [' . $version . ']')));
    $breaking  = hasMarker($section);
    $nextMinor = sprintf('0.%d.0', (int) $p[2] + 1);

    if ((int) $n[2] > (int) $p[2]) {
        if ($version !== $nextMinor) {
            $problems[] = sprintf('  [%s]  follows %s, so a middle bump is %s', $version, $previous, $nextMinor);
        }

        if (!$breaking) {
            $problems[] = sprintf(
                '  [%s]  raises the middle number over %s without a breaking entry'
                . ' — release it as 0.%d.%d, or mark the breaking change with `%s`',
                $version,
                $previous,
                (int) $p[2],
                (int) $p[3] + 1,
                MARKER,
            );
        }
    } elseif ($breaking) {
        $problems[] = sprintf(
            '  [%s]  has a breaking entry but keeps the middle number of %s — release it as %s',
            $version,
            $previous,
            $nextMinor,
        );
    }
}

if ($problems === []) {
    fwrite(STDOUT, sprintf(
        "check-changelog-version-bump: %d release(s) after %s match their breaking entries\n",
        $checked,
        RULE_SINCE,
    ));
    exit(0);
}

fwrite(STDERR, sprintf(
    "Release versions or breaking markers in %s do not follow the 0.x rule:\n\n%s\n\n"
    . "During 0.x the middle number rises only for a breaking change, by one, because\n"
    . "`^0.N` admits only 0.N.*: every middle bump makes each dependent extension\n"
    . "widen its constraint and release. A breaking entry is a top-level bullet that\n"
    . "opens with `- **BREAKING`. See Documentation/Api/Stability.rst,\n"
    . "\"Versioning during 0.x\".\n",
    $path,
    implode("\n", $problems),
));

exit(1);
