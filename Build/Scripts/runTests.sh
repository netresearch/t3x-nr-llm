#!/usr/bin/env bash

#
# Bootstrap for the shared TYPO3 extension test runner.
#
# Copy this file to Build/Scripts/runTests.sh in an extension. It is NOT the
# runner: the runner is versioned once in netresearch/typo3-ci-workflows and
# composer links it to .Build/bin/runTests.sh. This stub exists only because
# the runner provisions the very environment it lives in, so it cannot be
# behind a completed `composer install` — the first run on a fresh clone has
# to create it.
#
# Per-extension settings belong in Build/Scripts/runTests.conf, never here.
# Anything this stub grows beyond handing over is drift; fix the shared runner
# instead.
#

set -euo pipefail

cd "$(dirname "$0")/../.."

RUNNER=".Build/bin/runTests.sh"

if [[ ! -x "${RUNNER}" ]]; then
    echo "runTests.sh: ${RUNNER} not found — installing dependencies first." >&2
    if ! type composer >/dev/null 2>&1; then
        echo "runTests.sh: composer is not on PATH, cannot bootstrap ${RUNNER}." >&2
        exit 1
    fi
    composer install --no-interaction --no-progress
fi

if [[ ! -x "${RUNNER}" ]]; then
    echo "runTests.sh: ${RUNNER} is still missing after composer install." >&2
    echo "             Is netresearch/typo3-ci-workflows in require-dev?" >&2
    exit 1
fi

exec "${RUNNER}" "$@"
