<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Shared by check-changelog-released-sections.php and
 * check-changelog-version-bump.php, so both read the same section boundaries.
 */

/**
 * Split a CHANGELOG into its released sections, keyed by version, in file order.
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

    $parts    = preg_split('/^(?=## \[)/m', $text) ?: [];
    $sections = [];
    foreach ($parts as $part) {
        if (preg_match('/^## \[(\d[^\]]*)\]/', $part, $h) === 1) {
            $sections[$h[1]] = $part;
        }
    }

    return $sections;
}
