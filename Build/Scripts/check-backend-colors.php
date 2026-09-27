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
 * (`badge-*`, `btn-default`, `callout-*`, `alert-*`) are built on those
 * variables. Colour that bypasses them breaks one of the two schemes, and
 * nothing but a person looking at the page in both notices. Measured on
 * 2026-09-27: 245 axe contrast violations in dark and 27 in light across the
 * nr-llm views.
 *
 * Scanned: Fluid templates and partials, backend CSS, backend JavaScript
 * (not the vendored Chart.js), and the icons in Resources/Public/Icons.
 * Comments are ignored. Refused:
 *
 *  1. colour literals — hex, rgb()/rgba()/hsl()/hsla()/hwb()/lab()/lch()/
 *     oklab()/oklch()/color() — anywhere, and any of the 148 CSS named
 *     colours as the value of a property that carries colour (color,
 *     background, border, outline, fill, stroke, box-shadow, text-shadow,
 *     caret-color, accent-color, text-decoration, column-rule, …), whether
 *     written as a declaration (`color: black`), a JavaScript style
 *     assignment (`el.style.backgroundColor = 'white'`) or a
 *     setProperty() call. A value inside light-dark() carries both schemes
 *     and passes;
 *  2. `var(--bs-…)`: TYPO3 never sets data-bs-theme, so Bootstrap's variables
 *     keep their light values in the dark scheme;
 *  3. own scheme switches — `@media (prefers-color-scheme` and
 *     `[data-color-scheme` selectors: an unguarded media query renders dark
 *     for a user who chose Light while the OS is dark;
 *  4. Bootstrap classes that core's backend CSS does not define, or defines
 *     for one scheme only: `text-bg-*`, `bg-light|white|dark|body*|*-subtle`,
 *     `text-dark|white|black|light`, `text-body*` (not defined on 13.4.35 or
 *     14.3.7, so it does nothing — core's muted text is `text-variant`),
 *     `table-light|dark`, `btn-light|dark|secondary`, `btn-outline-*` (not
 *     defined on either), `alert-light|dark`, and a Bootstrap `bg-*` colour
 *     on a badge. Core uses `badge badge-*` and `btn-default` (v14.3.7: 585
 *     btn-default, 2 btn-secondary in templates). `alert-*` itself is allowed:
 *     13.4.35 and 14.3.7 define alert-default|primary|secondary|info|notice|
 *     success|warning|danger on the surface-container tokens, and core's own
 *     templates use them (14 uses at v14.3.7);
 *  5. in an icon (every SVG except `*.legacy.svg`, the v13 teal tiles, and
 *     Extension.svg, the full-colour extension tile), any fill, stroke,
 *     stop-color, color, flood-color or lighting-color other than
 *     `currentColor`, `none` or `var(--nr-icon-accent, <fallback>)`: an icon
 *     is drawn in the text colour so it follows the scheme.
 *
 * Exemption: a comment containing `scheme-independent: <reason>` exempts the
 * rules in 1 for exactly one statement — the code on the comment's own line
 * if there is any, otherwise the statement that follows it: in CSS and
 * JavaScript up to the first `;` outside brackets, the `}` that closes a
 * whole rule, or the `}` that closes the enclosing block, whichever comes
 * first; in a template the next line. Nothing else is exempted.
 *
 * Usage: check-backend-colors.php [root]   (root defaults to the repository)
 */

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');

$sources = [
    'Resources/Private' => ['html'],
    'Resources/Public/Css' => ['css'],
    'Resources/Public/JavaScript/Backend' => ['js'],
    'Resources/Public/Icons' => ['svg'],
];

/** Icons that are not drawn in currentColor, and why. */
$iconExceptions = [
    // The extension tile: its own teal background with white and orange
    // marks, right on either scheme (Resources/AGENTS.md).
    'Extension.svg',
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
        if (!$file->isFile() || !in_array($file->getExtension(), $extensions, true)) {
            continue;
        }
        if ($file->getExtension() === 'svg'
            && (str_ends_with($file->getFilename(), '.legacy.svg') || in_array($file->getFilename(), $iconExceptions, true))) {
            continue;
        }
        $files[] = $file->getPathname();
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

/**
 * Line indexes a `scheme-independent:` comment exempts: exactly one statement.
 *
 * @param list<string> $rawLines
 * @param list<string> $codeLines comment-free lines
 *
 * @return array<int, true>
 */
function exemptLines(array $rawLines, array $codeLines, string $extension): array
{
    $exempt = [];
    foreach ($rawLines as $index => $raw) {
        if (!str_contains($raw, 'scheme-independent:')) {
            continue;
        }
        // Code on the comment's own line: that line is the statement.
        if (trim($codeLines[$index]) !== '') {
            $exempt[$index] = true;
            continue;
        }
        // Otherwise the first code line after the comment starts the statement.
        $start = $index + 1;
        while (isset($codeLines[$start]) && trim($codeLines[$start]) === '') {
            $start++;
        }
        if (!isset($codeLines[$start])) {
            continue;
        }
        if ($extension !== 'css' && $extension !== 'js') {
            $exempt[$start] = true;
            continue;
        }
        $depth = 0;
        for ($line = $start; isset($codeLines[$line]); $line++) {
            $exempt[$line] = true;
            $end = false;
            foreach (str_split($codeLines[$line]) as $char) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                    // Leaving the enclosing block, or closing a whole rule.
                    $end = $end || $depth < 0 || ($char === '}' && $depth === 0);
                } elseif ($char === ';' && $depth <= 0) {
                    $end = true;
                }
            }
            if ($end) {
                break;
            }
        }
    }

    return $exempt;
}

// The CSS named colours (CSS Color Module Level 4), longest first so an
// alternation never stops at a prefix (`green` before `greenyellow`).
$namedColours = [
    'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black', 'blanchedalmond',
    'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral', 'cornflowerblue',
    'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey',
    'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange', 'darkorchid', 'darkred', 'darksalmon',
    'darkseagreen', 'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
    'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia',
    'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green', 'greenyellow', 'grey', 'honeydew', 'hotpink',
    'indianred', 'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue',
    'lightcoral', 'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink',
    'lightsalmon', 'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue',
    'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue',
    'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
    'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace',
    'olive', 'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise',
    'palevioletred', 'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple',
    'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna',
    'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'teal',
    'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
];
usort($namedColours, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
$named = implode('|', $namedColours);

// A property that carries colour, in CSS spelling (`box-shadow`) and in the
// DOM's camelCase spelling (`boxShadow`).
$colourProperty = '(?:[a-z-]*-)?(?:color|background|border|outline|fill|stroke|shadow|text-decoration|column-rule|text-emphasis)(?:-[a-z]+)*';
$colourPropertyCamel = '(?:color|background|border|outline|fill|stroke|boxShadow|textShadow|caretColor|accentColor|textDecoration|columnRule|textEmphasis|stopColor|floodColor|lightingColor|scrollbarColor)[A-Za-z]*';

$rules = [
    'colour literal' => '/(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(|(?<![\w.-])color\(/',
    'named colour' => '/(?:^|[\s;{"\'`])' . $colourProperty . '\s*:\s*[^;"\'`}]*(?<![\w-])(?:' . $named . ')(?![\w-])/i',
    'named colour in a style assignment' => '/\.style\.' . $colourPropertyCamel . '\s*=\s*[\'"`][^\'"`]*(?<![\w-])(?:' . $named . ')(?![\w-])'
        . '|setProperty\(\s*[\'"]' . $colourProperty . '[\'"]\s*,\s*[\'"`][^\'"`]*(?<![\w-])(?:' . $named . ')(?![\w-])/i',
    'Bootstrap variable' => '/var\(--bs-/',
    'own scheme switch' => '/@media\s*\(\s*prefers-color-scheme|\[data-color-scheme/',
    'scheme-pinned class' => '/(?<![\w-])(?:text-bg-[a-z]+|bg-(?:light|white|dark|body(?:-[a-z]+)?|[a-z]+-subtle)|text-(?:dark|white|black|light)|text-body(?:-[a-z]+)?|table-(?:light|dark)|btn-(?:light|dark|secondary)|btn-outline-[a-z]+|alert-(?:light|dark))(?![\w-])/',
    'Bootstrap colour on a badge' => '/\bbadge\b[^"\'`>]*(?<![\w-])bg-(?:primary|secondary|success|info|warning|danger)(?![\w-])/',
];
$exemptable = ['colour literal', 'named colour', 'named colour in a style assignment'];

$iconPaint = '/(?:^|[\s;"\'])(fill|stroke|stop-color|color|flood-color|lighting-color)\s*(?:=\s*"([^"]*)"|=\s*\'([^\']*)\'|:\s*([^;"\']*))/i';
$allowedPaint = '/^(?:currentColor|none|var\(--nr-icon-accent\s*,\s*#[0-9a-fA-F]{3,8}\s*\))$/i';

$offenders = [];
foreach ($files as $file) {
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    $relative = substr($file, strlen($root) + 1);
    $raw = (string)file_get_contents($file);
    $rawLines = explode("\n", $raw);
    $lines = explode("\n", stripComments($raw, $extension === 'svg' ? 'html' : $extension));

    if ($extension === 'svg') {
        foreach ($lines as $index => $line) {
            if (preg_match_all($iconPaint, $line, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $value = trim(($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? ''));
                if (preg_match($allowedPaint, $value) !== 1) {
                    $offenders[] = sprintf('%s:%d  icon paint: %s="%s" (allowed: currentColor, none, var(--nr-icon-accent, …))', $relative, $index + 1, $match[1], $value);
                }
            }
        }
        continue;
    }

    $exempt = exemptLines($rawLines, $lines, $extension);
    foreach ($lines as $index => $line) {
        $line = stripLightDark($line);
        foreach ($rules as $name => $pattern) {
            if (isset($exempt[$index]) && in_array($name, $exemptable, true)) {
                continue;
            }
            $subject = str_starts_with($name, 'named colour') ? stripPropertyNames($line) : $line;
            if (preg_match($pattern, $subject) === 1) {
                $offenders[] = sprintf('%s:%d  %s: %s', $relative, $index + 1, $name, trim($rawLines[$index]));
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
fwrite(STDERR, "\nUse the --typo3-* variables or core classes (badge-*, btn-default, callout-*, alert-*, text-variant);\n");
fwrite(STDERR, "light-dark() for a colour core has no variable for; `scheme-independent: <reason>` only where one value is right in both schemes.\n");

exit(1);
