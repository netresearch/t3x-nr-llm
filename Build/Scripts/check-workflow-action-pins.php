#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse a workflow that references a third-party action by tag instead of by
 * commit SHA.
 *
 * Both this repository and the `netresearch` organisation set
 * `sha_pinning_required` on their Actions permissions. A tag ref does not
 * produce a normal check failure: the run dies at `Set up job`, before any step
 * executes, so the log carries no step output and the failure reads as
 * unrelated to the change. On 2026-08-11 a job added with two tag refs turned
 * six checks red at once — `Set up job`, zizmor, Opengrep, CodeQL, SonarCloud
 * and the aggregate security gate — every one of them naming the same two lines.
 *
 * CI already runs zizmor, which catches this. It runs it on the pull request,
 * after the push. This is the same rule a second earlier, where it costs
 * nothing.
 *
 * ONE deliberate exception: `netresearch/*` reusable workflows are referenced at
 * `@main` on purpose — this repository tracks the org's shared CI rather than
 * pinning it, which is what makes a fix there reach every extension at once.
 * Those are workflow references, not actions; the policy applies to actions.
 *
 * `uses:` is always a single scalar on one line in a workflow file, so a
 * line-oriented match is exact here — no YAML parse is needed and none of the
 * usual structured-file caveats apply.
 */

$root  = dirname(__DIR__, 2);
$files = glob($root . '/.github/workflows/*.y{a,}ml', GLOB_BRACE) ?: [];

if ($files === []) {
    exit(0);
}

/** A full commit SHA — the only form the policy accepts. */
const PINNED = '/^[0-9a-f]{40}$/';

/** Organisation-internal reusable workflows, tracked at a branch on purpose. */
const INTERNAL_PREFIX = 'netresearch/';

$problems = [];

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*-?\s*uses:\s*(\S+)\s*(?:#.*)?$/', $line, $m) !== 1) {
            continue;
        }

        $ref = trim($m[1], '"\'');
        if (str_starts_with($ref, INTERNAL_PREFIX)) {
            continue;
        }

        if (!str_contains($ref, '@')) {
            $problems[] = sprintf('%s:%d  %s  (no ref at all)', basename($file), $i + 1, $ref);

            continue;
        }

        [, $version] = explode('@', $ref, 2);
        if (preg_match(PINNED, $version) !== 1) {
            $problems[] = sprintf('%s:%d  %s', basename($file), $i + 1, $ref);
        }
    }
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "These workflow actions are not pinned to a commit SHA:\n\n%s\n\n"
    . "Both this repository and the netresearch organisation set\n"
    . "sha_pinning_required, so a tag ref fails at `Set up job` — before any step\n"
    . "runs — and every workflow-security scanner flags the same lines on top.\n\n"
    . "Resolve the tag once and pin it:\n\n"
    . "  gh api repos/<owner>/<action>/git/ref/tags/<tag> --jq '.object.sha'\n"
    . "  uses: <owner>/<action>@<sha> # <tag>\n\n"
    . "Cheaper still: drop an action the runner makes unnecessary (setup-php for a\n"
    . "script that needs no extensions, setup-node for a plain npx).\n\n"
    . "netresearch/* reusable workflows are exempt — they are tracked at @main on\n"
    . "purpose so a fix in the shared CI reaches every extension at once.\n",
    implode("\n", array_map(static fn(string $p): string => '  ' . $p, $problems)),
));

exit(1);
