#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse `text-bg-danger` on a badge in a Fluid template.
 *
 * White on the TYPO3 backend's danger red is 4.37:1 at the 11px a badge
 * computes to, under the 4.5:1 WCAG AA needs for normal text. axe rates it
 * `serious`. `text-bg-warning` is black on #e0a810 at 9.6:1 and is what the
 * states this project marks actually are: an operator resolves them, they are
 * not failures of the system.
 *
 * Do NOT reach for `.bg-danger-subtle` + `.text-danger-emphasis` instead. The
 * backend CSS ships the background utility but not the text one — only the CSS
 * variable — so the pair leaves the label at the body colour and inverts badly
 * in one of the two themes. Checked against
 * `typo3/cms-backend/Resources/Public/Css/backend.css`.
 *
 * This check enforces that decision; it does NOT measure contrast. The
 * accessibility suite is the thing that measures, and it can only measure a
 * state some fixture actually renders — which is how four of these survived
 * a green suite until 2026-08-11 (#746). A static refusal covers the states no
 * fixture reaches.
 *
 * Alerts are out of scope: `alert-danger` is dark text on a light tint, a
 * different component with a different pairing.
 */

// Walked rather than globbed: `**` does not recurse in glob(), and a pattern
// that silently matches one directory level would pass this check by missing
// the file, which is the failure mode the check exists to prevent.
$root  = dirname(__DIR__, 2);
$files = [];
foreach (['Templates', 'Partials'] as $dir) {
    $path = $root . '/Resources/Private/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'html') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

$offenders = [];
foreach ($files as $file) {
    foreach (explode("\n", (string)file_get_contents($file)) as $number => $line) {
        // The class attribute of a badge, not the word in a comment explaining
        // why it is absent.
        if (preg_match('/class="[^"]*\bbadge\b[^"]*\btext-bg-danger\b/', $line) === 1) {
            $offenders[] = sprintf('%s:%d', substr($file, strlen($root) + 1), $number + 1);
        }
    }
}

if ($offenders === []) {
    exit(0);
}

fwrite(STDERR, "text-bg-danger on a badge fails WCAG AA (4.37:1 at badge size).\n");
fwrite(STDERR, "Use text-bg-warning — see the header of this script for why, and why not the subtle pair.\n\n");
foreach ($offenders as $offender) {
    fwrite(STDERR, '  ' . $offender . "\n");
}

exit(1);
