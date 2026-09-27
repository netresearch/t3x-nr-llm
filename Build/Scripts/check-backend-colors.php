#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse colour in the backend UI that ignores the backend colour scheme.
 *
 * TYPO3 13.4 and 14.3 define every `--typo3-*` colour once with light-dark()
 * and select the scheme from html[data-color-scheme]; the core classes
 * (`badge-*`, `btn-default`, `callout-*`) are built on those variables. Colour
 * that bypasses them breaks one of the two schemes, and nothing but a person
 * looking at the page in both notices. Measured on 2026-09-27: 245 axe
 * contrast violations in dark and 27 in light across the nr-llm views.
 *
 * Scanned: Fluid templates and partials, backend CSS, backend JavaScript
 * (not the vendored Chart.js). Comments are ignored. Refused:
 *
 *  1. colour literals — hex, rgb()/rgba()/hsl()/hsla(), named colours in a
 *     colour property — unless they sit inside light-dark(), which carries
 *     both schemes, or a comment `scheme-independent: <reason>` stands on the
 *     line or opens the block it belongs to (the block ends at a blank line);
 *  2. `var(--bs-…)`: TYPO3 never sets data-bs-theme, so Bootstrap's variables
 *     keep their light values in the dark scheme;
 *  3. own scheme switches — `@media (prefers-color-scheme` and
 *     `[data-color-scheme` selectors: an unguarded media query renders dark
 *     for a user who chose Light while the OS is dark;
 *  4. Bootstrap classes that pin one scheme or bypass the core palette:
 *     `text-bg-*`, `bg-light|white|dark|body*|*-subtle`, `text-dark|white|
 *     black|light`, `table-light|dark`, `btn-light|dark|secondary`, and a
 *     Bootstrap `bg-*` colour on a badge. Core uses `badge badge-*` and
 *     `btn-default` (v14.3.7: 585 btn-default, 2 btn-secondary in templates).
 *
 * Usage: check-backend-colors.php [root]   (root defaults to the repository)
 */

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');

$sources = [
    'Resources/Private' => ['html'],
    'Resources/Public/Css' => ['css'],
    'Resources/Public/JavaScript/Backend' => ['js'],
];

$files = [];
foreach ($sources as $dir => $extensions) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    // Walked rather than globbed: `**` does not recurse in glob(), and a
    // pattern that silently matches one level would pass by missing the file.
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

/**
 * Blank out comments, keeping every newline so line numbers survive.
 */
function stripComments(string $text, string $extension): string
{
    $blank = static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '';
    $patterns = match ($extension) {
        'css' => ['#/\*.*?\*/#s'],
        // `//` only at a line start or after whitespace, so URLs survive.
        'js' => ['#/\*.*?\*/#s', '#(?<=^|\s)//[^\n]*#m'],
        default => ['#<!--.*?-->#s', '#<f:comment>.*?</f:comment>#s'],
    };
    foreach ($patterns as $pattern) {
        $text = preg_replace_callback($pattern, $blank, $text) ?? $text;
    }

    return $text;
}

/**
 * Blank out light-dark(…) arguments: a pair that names both schemes is the fix, not the fault.
 */
function stripLightDark(string $line): string
{
    return preg_replace_callback(
        '/light-dark\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/',
        static fn(array $m): string => str_repeat(' ', strlen($m[0])),
        $line,
    ) ?? $line;
}

/**
 * Blank out custom-property names, which may contain a colour word (`--pg-teal`).
 */
function stripPropertyNames(string $line): string
{
    return preg_replace_callback('/--[\w-]+/', static fn(array $m): string => str_repeat(' ', strlen($m[0])), $line) ?? $line;
}

$named = 'white|black|red|green|blue|gray|grey|silver|yellow|orange|purple|navy|maroon|teal|lime|aqua|fuchsia|olive';
$rules = [
    'colour literal' => '/(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|\b(?:rgba?|hsla?)\(/',
    'named colour' => '/(?:^|[\s;{"\'`])(?:color|background(?:-color)?|border(?:-[a-z]+)?-color|border|outline(?:-color)?|fill|stroke)\s*:\s*[^;"\'`}]*\b(?:' . $named . ')\b/i',
    'Bootstrap variable' => '/var\(--bs-/',
    'own scheme switch' => '/@media\s*\(\s*prefers-color-scheme|\[data-color-scheme/',
    'scheme-pinned class' => '/(?<![\w-])(?:text-bg-[a-z]+|bg-(?:light|white|dark|body(?:-[a-z]+)?|[a-z]+-subtle)|text-(?:dark|white|black|light)|table-(?:light|dark)|btn-(?:light|dark|secondary))(?![\w-])/',
    'Bootstrap colour on a badge' => '/\bbadge\b[^"\'`>]*(?<![\w-])bg-(?:primary|secondary|success|info|warning|danger)(?![\w-])/',
];

$offenders = [];
foreach ($files as $file) {
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    $raw = (string)file_get_contents($file);
    $rawLines = explode("\n", $raw);
    $lines = explode("\n", stripComments($raw, $extension));
    $exemptBlock = false;
    foreach ($lines as $index => $line) {
        if (trim($rawLines[$index]) === '') {
            $exemptBlock = false;
        }
        if (str_contains($rawLines[$index], 'scheme-independent:')) {
            $exemptBlock = true;
        }
        $exempt = $exemptBlock;
        $line = stripLightDark($line);
        foreach ($rules as $name => $pattern) {
            if ($exempt && ($name === 'colour literal' || $name === 'named colour')) {
                continue;
            }
            $subject = $name === 'named colour' ? stripPropertyNames($line) : $line;
            if (preg_match($pattern, $subject) === 1) {
                $offenders[] = sprintf('%s:%d  %s: %s', substr($file, strlen($root) + 1), $index + 1, $name, trim($rawLines[$index]));
            }
        }
    }
}

if ($offenders === []) {
    exit(0);
}

fwrite(STDERR, "Colour that ignores the backend colour scheme (see the header of this script):\n\n");
foreach ($offenders as $offender) {
    fwrite(STDERR, '  ' . $offender . "\n");
}
fwrite(STDERR, "\nUse the --typo3-* variables or core classes (badge-*, btn-default, callout-*);\n");
fwrite(STDERR, "light-dark() for a colour core has no variable for; `scheme-independent: <reason>` only where one value is right in both schemes.\n");

exit(1);
