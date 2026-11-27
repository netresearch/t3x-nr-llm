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
 *   - a release that raises the middle number (0.N.x → 0.N+1.0) carries at
 *     least one breaking entry,
 *   - a release that keeps the middle number carries none.
 *
 * A breaking entry is a top-level bullet that opens with `- **BREAKING`. The
 * word anywhere else does not count, so the check and a reader find the same
 * line. What is breaking is decided by the author of the release: a breaking
 * diff of Tests/Unit/Api/api-surface.txt, or a behaviour change consumers have
 * to handle. This script only checks that the version number agrees with what
 * the CHANGELOG says.
 *
 * Only releases after RULE_SINCE are judged. Released sections are frozen by
 * check-changelog-released-sections.php, and the older ones break the rule.
 *
 * Usage: check-changelog-version-bump.php [CHANGELOG path]
 */

/** The last release before the rule; releases after it are checked. */
const RULE_SINCE = '0.55.1';

const BREAKING_MARKER = '/^- \*\*BREAKING/m';

$path = $argv[1] ?? dirname(__DIR__, 2) . '/CHANGELOG.md';

if (!is_readable($path)) {
    fwrite(STDERR, sprintf("check-changelog-version-bump: cannot read %s\n", $path));
    exit(2);
}

require_once __DIR__ . '/changelog-sections.php';

$sections = releasedSections((string) file_get_contents($path));
$versions = array_map('strval', array_keys($sections));

$problems = [];
$checked  = 0;
for ($i = 0; $i < count($versions) - 1; ++$i) {
    $newer = $versions[$i];
    $older = $versions[$i + 1];
    if (preg_match('/^0\.(\d+)\.(\d+)$/', $newer, $n) !== 1 || preg_match('/^0\.(\d+)\.\d+$/', $older, $o) !== 1) {
        continue;
    }

    if (version_compare($newer, RULE_SINCE, '<=')) {
        continue;
    }

    ++$checked;
    $middleRaised = (int) $n[1] > (int) $o[1];
    $breaking     = preg_match(BREAKING_MARKER, $sections[$newer]) === 1;
    if ($middleRaised && !$breaking) {
        $problems[] = sprintf(
            '  [%s]  raises the middle number over %s without a breaking entry'
            . ' — release it as %s, or mark the breaking change with `- **BREAKING`',
            $newer,
            $older,
            preg_replace_callback('/\d+$/', static fn (array $p): string => (string) ((int) $p[0] + 1), $older),
        );
    } elseif (!$middleRaised && $breaking) {
        $problems[] = sprintf(
            '  [%s]  has a breaking entry but keeps the middle number of %s'
            . ' — release it as 0.%d.0',
            $newer,
            $older,
            (int) $o[1] + 1,
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
    "Release versions in %s do not match their breaking entries:\n\n%s\n\n"
    . "During 0.x the middle number rises only for a breaking change, because\n"
    . "`^0.N` admits only 0.N.*: every middle bump makes each dependent extension\n"
    . "widen its constraint and release. See Documentation/Api/Stability.rst,\n"
    . "\"Versioning during 0.x\".\n",
    $path,
    implode("\n", $problems),
));

exit(1);
