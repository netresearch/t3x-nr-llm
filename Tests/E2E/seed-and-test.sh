#!/usr/bin/env bash
#
# CI-only E2E seed hook (ADR-109). The e2e reusable workflow
# (netresearch/typo3-ci-workflows) runs this as its `test-command` AFTER TYPO3
# setup and the PHP server start, so the schema exists and the backend is live.
# It seeds two waiting agent runs (WAITING_FOR_APPROVAL + WAITING_FOR_INPUT) so
# the accessibility suite can axe the approve/deny and schema-input forms of the
# Agent Runs inbox, then hands off to the default Playwright run.
#
# DB coordinates are the fixed defaults the reusable workflow's default mode
# provisions (see its "Setup TYPO3" step): host 127.0.0.1, root/root, db typo3.
set -euo pipefail

if command -v mysql >/dev/null 2>&1; then
  DB_CLIENT="mysql"
elif command -v mariadb >/dev/null 2>&1; then
  DB_CLIENT="mariadb"
else
  echo "::error::No mysql/mariadb client on the runner; cannot seed the E2E database." >&2
  exit 1
fi

echo "Seeding demo waiting agent runs for the E2E accessibility suite (${DB_CLIENT})..."
"${DB_CLIENT}" -h 127.0.0.1 -u root -proot typo3 < Tests/E2E/Playwright/fixtures/agentrun-seed.sql

echo "Running Playwright E2E suite..."
npm run test:e2e
