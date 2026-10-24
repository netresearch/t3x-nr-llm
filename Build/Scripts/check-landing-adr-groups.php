#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse an ADR that the landing page's catalogue does not place in a group.
 *
 * `landingpage/build/build.py` renders every `Documentation/Adr/Adr*.rst`, and
 * an ADR missing from `landingpage/build/data/adr.json` lands in a catch-all
 * "Other" group. Nothing failed when that happened: ADR-091 to ADR-228 were all
 * filed there, 138 records, before anyone looked at the page.
 *
 * The check runs here, in `ci:test:repo`, and not in the Pages build, because
 * the ADR pull request is the one that can fix it. The Pages build runs after
 * the merge, and a failure there would block an unrelated deploy.
 */

$root = dirname(__DIR__, 2);

$catalogue = json_decode(
    (string) file_get_contents($root . '/landingpage/build/data/adr.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$grouped = [];
foreach ($catalogue['groups'] ?? [] as $group) {
    foreach ($group['numbers'] ?? [] as $number) {
        $grouped[(int) $number][] = (string) $group['name'];
    }
}

$problems = [];

foreach (glob($root . '/Documentation/Adr/Adr*.rst') ?: [] as $path) {
    if (preg_match('/^Adr(\d+)/', basename($path), $match) !== 1) {
        continue;
    }

    $number = (int) $match[1];
    $groups = $grouped[$number] ?? [];

    if ($groups === []) {
        $problems[] = sprintf('  ADR-%03d (%s): in no group', $number, basename($path));
    } elseif (count($groups) > 1) {
        $problems[] = sprintf('  ADR-%03d (%s): in %s', $number, basename($path), implode(' and ', $groups));
    }
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "The landing page's ADR catalogue does not place these records:\n\n%s\n\n"
    . "Add each number to exactly one group under \"groups\" in\n"
    . "landingpage/build/data/adr.json. A record in no group is shown under\n"
    . "\"Other\" on https://netresearch.github.io/t3x-nr-llm/adr/ .\n",
    implode("\n", $problems),
));

exit(1);
