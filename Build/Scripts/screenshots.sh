#!/usr/bin/env bash

#
# Capture the documentation screenshots — without ddev, without a database
# server, without anything this repository does not already depend on.
#
# The pieces were all here; nothing tied them together. `runTests.sh -s e2e`
# brings the browser but says so itself: it "drives a browser against a running
# TYPO3. This script does not [start one]." The one local answer to that was
# E2E_BASE_URL pointing at a ddev site, which is why taking a screenshot needed
# a tool meant for humans. TYPO3 installs non-interactively onto SQLite and PHP
# serves it, so the whole thing runs anywhere the test suite runs.
#
#   Build/Scripts/screenshots.sh [--port 8088] [--days 90] [--keep]
#
# --keep leaves the instance and the server running so you can look at it.
#

set -euo pipefail

cd "$(dirname "$0")/../.."

PORT=8088
DAYS=90
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --port) PORT="${2:?--port needs a number}"; shift 2 ;;
        --days) DAYS="${2:?--days needs a number}"; shift 2 ;;
        --keep) KEEP=1; shift ;;
        -h|--help) sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "screenshots.sh: unknown argument: $1" >&2; exit 2 ;;
    esac
done

export TYPO3_CONTEXT=Development
# Two URLs on purpose: the server and this script talk over loopback, the
# browser lives in a container and reaches the same server by host alias.
LOCAL_URL="http://127.0.0.1:${PORT}"
CONTAINER_URL="http://host.docker.internal:${PORT}"
SERVER_PID=""

# The password the Playwright fixtures default to. Named here rather than left
# implicit: the fixtures fall back to it, so an install with any other password
# fails at the login step with a message about a selector.
ADMIN_PASSWORD="${TYPO3_PASSWORD:-Joh316!!}"

cleanup() {
    if [ -n "${SERVER_PID}" ] && kill -0 "${SERVER_PID}" 2>/dev/null; then
        # Only ever this script's own server. A pkill pattern here would also
        # match the invoking shell's command line and take the run with it.
        kill "${SERVER_PID}" 2>/dev/null || true
        wait "${SERVER_PID}" 2>/dev/null || true
    fi
}
[ "${KEEP}" = "1" ] || trap cleanup EXIT

if [ ! -x .Build/bin/typo3 ]; then
    echo "screenshots.sh: .Build/bin/typo3 is missing — run composer install first." >&2
    exit 1
fi

echo "==> Installing TYPO3 on SQLite"
mkdir -p var/sqlite
# A fresh database every run. `setup --force` rewrites the settings but
# leaves an existing admin account alone, so a second run with a different
# password silently keeps the first one — which surfaces as a login timeout
# inside Playwright rather than as a wrong password.
rm -f var/sqlite/screenshots*
# Caches outlive the database, and a rate-limit entry there survives an
# otherwise fresh install.
rm -rf var/cache
php .Build/bin/typo3 setup \
    --driver=sqlite \
    --dbname=screenshots \
    --admin-username=admin \
    --admin-user-password="${ADMIN_PASSWORD}" \
    --admin-email=dev@example.invalid \
    --project-name='nr_llm screenshots' \
    --create-site="${LOCAL_URL}/" \
    --server-type=other \
    --force --no-interaction --silent

# The container reaches the server by a name the install never saw, and TYPO3
# rejects a Host header outside its trusted pattern with a 500 that reads like
# the server never started. Wide open is correct for a throwaway instance that
# listens on loopback and is torn down at the end of this script.
php -r '
    $f = "config/system/settings.php";
    $c = require $f;
    $c["SYS"]["trustedHostsPattern"] = ".*";
    // The backend rate-limits failed logins per IP. Every run logs in eight
    // times from one address, and one wrong password early on locks the rest
    // out with a 403 that looks nothing like a credentials problem.
    $c["BE"]["loginRateLimit"] = 0;
    file_put_contents($f, "<?php" . PHP_EOL . "return " . var_export($c, true) . ";" . PHP_EOL);
'

php .Build/bin/typo3 extension:setup --quiet

echo "==> Seeding demo data (${DAYS} days)"
php .Build/bin/typo3 nrllm:demo:seed --days="${DAYS}"

echo "==> Serving ${LOCAL_URL} (container reaches it as ${CONTAINER_URL})"
# 0.0.0.0, not 127.0.0.1: the container connects from outside this loopback.
# PHP_CLI_SERVER_WORKERS: the built-in server is single-threaded by default,
# and the backend fires several requests per page. Without this the browser
# waits on a queue and the test dies of a navigation timeout.
PHP_CLI_SERVER_WORKERS=8 php -S "0.0.0.0:${PORT}" -t .Build/Web Build/Scripts/screenshot-router.php > var/screenshot-server.log 2>&1 &
SERVER_PID=$!

# Poll rather than sleep a fixed span: a fixed sleep is either flaky or slow,
# and the failure it produces (a connection refused inside Playwright) points
# at the browser rather than at the server.
for _ in $(seq 1 40); do
    if curl -fs -o /dev/null -m 2 "${LOCAL_URL}/typo3/login" 2>/dev/null; then
        break
    fi
    sleep 0.5
done

if ! curl -fsS -o /dev/null -m 5 "${LOCAL_URL}/typo3/login"; then
    echo "screenshots.sh: the server never answered. Last lines:" >&2
    tail -20 var/screenshot-server.log >&2
    exit 1
fi

# The shared runner (netresearch/typo3-ci-workflows v1.6.0) uses
# ${IMAGE_PLAYWRIGHT} but never assigns it — every sibling IMAGE_* is set
# around line 540 and this one is not — so docker reads /bin/bash as the
# image and answers "invalid reference format". Supplied here until that is
# fixed upstream, pinned to the version package-lock.json resolves: the
# image ships matching browsers, and a mismatch fails with "Executable
# doesn't exist at /ms-playwright/...".
PW_VERSION="$(node -p "require('./package-lock.json').packages['node_modules/@playwright/test'].version" 2>/dev/null || echo "")"
if [ -z "${PW_VERSION}" ]; then
    echo "screenshots.sh: could not read the Playwright version from package-lock.json." >&2
    exit 1
fi

echo "==> Capturing"
TYPO3_BASE_URL="${CONTAINER_URL}" TYPO3_PASSWORD="${ADMIN_PASSWORD}" \
    IMAGE_PLAYWRIGHT="${IMAGE_PLAYWRIGHT:-mcr.microsoft.com/playwright:v${PW_VERSION}-noble}" \
    ./Build/Scripts/runTests.sh -s e2e screenshots.spec.ts --workers=1

echo "==> Written to Documentation/Images/"
git status --porcelain Documentation/Images/ || true

if [ "${KEEP}" = "1" ]; then
    echo "==> --keep: ${LOCAL_URL} still serving as pid ${SERVER_PID} (admin / ${ADMIN_PASSWORD})"
fi
