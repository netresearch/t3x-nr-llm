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
 * Byte offset where the trailing block of link reference definitions starts:
 * the link definitions (and blank lines between them) that end the text after
 * the last `## [` heading. A link definition inside a section is part of that
 * section. Without such a block, the length of the text.
 */
function linkBlockStart(string $text): int
{
    $last = 0;
    if (preg_match_all('/^## \[/m', $text, $headings, PREG_OFFSET_CAPTURE) > 0) {
        $last = (int) end($headings[0])[1];
    }

    $start  = strlen($text);
    $offset = strlen($text);
    $lines  = explode("\n", substr($text, $last));
    for ($i = count($lines) - 1; $i > 0; --$i) {
        $offset -= strlen($lines[$i]) + 1;
        if (trim($lines[$i]) === '') {
            continue;
        }

        if (preg_match('/^\[[^\]]+\]: \S/', $lines[$i]) !== 1) {
            break;
        }

        $start = $offset + 1;
    }

    return $start;
}

/**
 * Split a CHANGELOG into its released sections, keyed by version, in file order.
 *
 * A section runs from its `## [X.Y.Z]` heading to the next `## [` heading, or
 * to the trailing link-reference block, or to the end of the text.
 *
 * @return array<string, string>
 */
function releasedSections(string $text): array
{
    $text = substr($text, 0, linkBlockStart($text));

    $parts    = preg_split('/^(?=## \[)/m', $text) ?: [];
    $sections = [];
    foreach ($parts as $part) {
        if (preg_match('/^## \[(\d[^\]]*)\]/', $part, $h) === 1) {
            $sections[$h[1]] = $part;
        }
    }

    return $sections;
}
