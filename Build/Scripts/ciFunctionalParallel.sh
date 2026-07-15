#!/usr/bin/env bash
#
# Run the functional suite with one PHPUnit process per test class.
#
# Functional tests are the CI critical path: eight matrix cells at 8-11min each
# while every other job finishes in ~1min, and 95% of a cell is a single serial
# PHPUnit process. Sharding by test class collapses that to ~2.5min per cell.
#
# SAFETY -- why one process per test FILE cannot collide:
# typo3/testing-framework keys every test instance on
#   substr(sha1(static::class), 0, 7)          (FunctionalTestCase::getInstanceIdentifier)
# and derives BOTH the instance directory and the database from it:
#   functional-<identifier>/                   (SQLite: one file per class)
#   $originalDatabaseName . '_ft' . $identifier  (FunctionalTestCase.php:361 -- one DB per class)
# so this is safe on SQLite and on MySQL/MariaDB alike. Do not shard by test
# METHOD (--filter): several methods of one class share an instance.
#
# Both globs are required: Build/FunctionalTests.xml runs the `functional` AND
# the `e2e-backend` suites. Dropping the latter is exactly how both suites
# rotted in #272.
#
# Deliberately NO opcache.enable_cli: one process per file means every process
# starts with a cold cache, so it buys nothing here (it is worth ~1.17x only for
# the single-process serial run), and it coincided with a DataHandler segfault
# on the 8.4/^14.3 cell.
#
# Output is buffered per class and replayed only for failures -- four
# interleaved PHPUnit streams are unreadable. A failing class exits non-zero so
# xargs returns 123 and the CI step fails.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

# XDEBUG_MODE overrides setup-php's xdebug.mode=coverage ini at runtime. Xdebug
# in coverage mode costs ~1.6x even when nothing is collected (61.0s vs 37.6s
# measured over 3 alternating repeats on an identical 126-test subset).
export XDEBUG_MODE=off

mkdir -p .Build/Web/typo3temp/var/tests/functional-sqlite-dbs .Build/logs/functional

# shellcheck disable=SC2016 # single quotes are deliberate: $1/$log must expand
# in the inner sh (once per test file), not in this shell.
find Tests/Functional Tests/E2E/Backend -name '*Test.php' -print0 \
  | xargs -0 -P"$(nproc)" -I{} sh -c '
      log=".Build/logs/functional/$(printf %s "$1" | tr / _).log"
      if ! php -d xdebug.mode=off .Build/bin/phpunit -c Build/FunctionalTests.xml "$1" > "$log" 2>&1; then
        echo "::group::FAILED $1"
        cat "$log"
        echo "::endgroup::"
        exit 1
      fi
    ' _ {}
