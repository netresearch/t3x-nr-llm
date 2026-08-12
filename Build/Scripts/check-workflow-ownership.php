#!/usr/bin/env php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Refuse a workflow job that defines its own steps.
 *
 * This repository's CI is delegated: every workflow calls a shared
 * `netresearch/*` reusable workflow, and the pinning, hardening and security
 * review of the actions those run happen once, in that shared repository, for
 * all thirty callers.
 *
 * A job with local `steps:` opts out of that. It has to carry its own action
 * pins, and getting them wrong does not read like a normal check failure: the
 * org and the repo both set `sha_pinning_required`, so a tag ref kills the run
 * at `Set up job` — before any step executes, so the log shows no step output —
 * and zizmor, Opengrep, CodeQL and SonarCloud then each flag the same lines. On
 * 2026-08-11 that cost six red checks for one mistake, none of them naming the
 * cause. Repo-specific checks belong in the shared workflow's `repo-checks` job
 * (`run-repo-checks: true` plus a `ci:test:repo` composer script).
 *
 * ONE exception, named explicitly rather than pattern-matched: the aggregate
 * gate in `checks.yml`. It evaluates the results of the other jobs, which a
 * reusable-workflow call cannot do.
 *
 * Uses `yq` when available because a job's shape is a nested key; falls back to
 * refusing to guess rather than to a regex.
 */

$root  = dirname(__DIR__, 2);
$files = glob($root . '/.github/workflows/*.y{a,}ml', GLOB_BRACE) ?: [];

if ($files === []) {
    exit(0);
}

exec('command -v yq', $out, $noYq);
if ($noYq !== 0) {
    fwrite(STDERR, "check-workflow-ownership: yq is required and was not found; skipping rather than guessing.\n");
    exit(0);
}

/** Jobs allowed to define their own steps, and why. */
const ALLOWED = [
    // Reads the other jobs' results; cannot be a reusable-workflow call.
    'checks.yml' => ['gate'],
];

$problems = [];

foreach ($files as $file) {
    $name = basename($file);
    $cmd  = sprintf('yq -r %s %s 2>/dev/null', escapeshellarg('.jobs | to_entries[] | select(.value.steps) | .key'), escapeshellarg($file));
    $jobs = array_filter(array_map('trim', explode("\n", (string)shell_exec($cmd))));

    foreach ($jobs as $job) {
        if (in_array($job, ALLOWED[$name] ?? [], true)) {
            continue;
        }

        $problems[] = sprintf('  %s: job "%s"', $name, $job);
    }
}

if ($problems === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "These workflow jobs define their own steps:\n\n%s\n\n"
    . "This repository delegates its CI to shared netresearch/* reusable workflows,\n"
    . "so action pinning, runner hardening and security review happen once there for\n"
    . "every caller. A local job opts out of all three, and a wrong pin does not fail\n"
    . "like a normal check: the run dies at `Set up job` before any step executes and\n"
    . "four scanners then flag the same lines.\n\n"
    . "For a repo-specific check, use the shared workflow instead:\n\n"
    . "  ci.yml:  run-repo-checks: true\n"
    . "  composer.json:  \"ci:test:repo\": \"...\"\n\n"
    . "If a job genuinely cannot be a reusable-workflow call — the aggregate gate is\n"
    . "the one case — add it to ALLOWED in this script with the reason.\n",
    implode("\n", $problems),
));

exit(1);
