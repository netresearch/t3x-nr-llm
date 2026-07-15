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

rm -rf .Build/logs/functional
mkdir -p .Build/Web/typo3temp/var/tests/functional-sqlite-dbs .Build/logs/functional

mapfile -t CLASSES < <(find Tests/Functional Tests/E2E/Backend -name '*Test.php' | sort)

# A glob that matches nothing must not pass as success. This is how #272 rotted:
# the suite stopped running and CI stayed green.
if [ "${#CLASSES[@]}" -eq 0 ]; then
    echo "::error::No functional test classes found under Tests/Functional or Tests/E2E/Backend."
    exit 1
fi

# shellcheck disable=SC2016 # single quotes are deliberate: $1/$log must expand
# in the inner sh (once per test file), not in this shell.
printf '%s\0' "${CLASSES[@]}" \
  | xargs -0 -P"$(nproc)" -I{} sh -c '
      log=".Build/logs/functional/$(printf %s "$1" | tr / _).log"
      if ! php -d xdebug.mode=off .Build/bin/phpunit -c Build/FunctionalTests.xml "$1" > "$log" 2>&1; then
        echo "::group::FAILED $1"
        cat "$log"
        echo "::endgroup::"
        exit 1
      fi
    ' _ {}

# Sharding buries the per-class output, so a green cell would otherwise print
# NOTHING proving it ran anything — the same silent-green shape as the coverage
# outage this script was written alongside. Aggregate the shards back into one
# visible summary, and refuse to report success on numbers that prove nothing.
#
# PHPUnit prints "OK (N tests, M assertions)" when clean and
# "Tests: N, Assertions: M, ..." when anything was raised, so both are parsed.
strip() { sed 's/\x1b\[[0-9;]*m//g' "$1"; }
tests=0; assertions=0; skipped=0; noresult=()
for cls in "${CLASSES[@]}"; do
    log=".Build/logs/functional/$(printf %s "$cls" | tr / _).log"
    if [ ! -s "$log" ]; then noresult+=("$cls"); continue; fi
    out="$(strip "$log")"
    if   [[ "$out" =~ OK\ \(([0-9]+)\ tests?,\ ([0-9]+)\ assertions?\) ]]; then
        tests=$((tests + BASH_REMATCH[1])); assertions=$((assertions + BASH_REMATCH[2]))
    elif [[ "$out" =~ Tests:\ ([0-9]+),\ Assertions:\ ([0-9]+) ]]; then
        tests=$((tests + BASH_REMATCH[1])); assertions=$((assertions + BASH_REMATCH[2]))
        [[ "$out" =~ Skipped:\ ([0-9]+) ]] && skipped=$((skipped + BASH_REMATCH[1]))
    else
        noresult+=("$cls")
    fi
done

echo "functional: ${#CLASSES[@]} classes, ${tests} tests, ${assertions} assertions, ${skipped} skipped"

if [ "${#noresult[@]}" -gt 0 ]; then
    echo "::error::${#noresult[@]} class(es) produced no PHPUnit result (crash/segfault): ${noresult[*]}"
    exit 1
fi

# AbstractFunctionalTestCase::setUp() converts ANY setUp Throwable into
# markTestSkipped(), so a database that is merely unreachable yields "OK" with
# zero assertions and exit 0. Proven locally against a not-yet-ready MariaDB:
# "Tests: 25, Assertions: 0, Skipped: 25" — green, having tested nothing.
if [ "$assertions" -eq 0 ]; then
    echo "::error::0 assertions across ${#CLASSES[@]} classes — the suite proved nothing (a database that is down makes every test skip)."
    exit 1
fi

# A partial collapse is the same failure wearing a smaller hat. The healthy
# baseline is 1 skip in ~1125 tests; 5% is far above noise and far below a
# collapse. A warning rather than a failure: the threshold is a judgement call,
# and a false red on a required check is worse than a visible warning.
if [ "$tests" -gt 0 ] && [ $((skipped * 100 / tests)) -ge 5 ]; then
    echo "::warning::${skipped}/${tests} tests skipped (>=5%) — expected ~1. Check the database is reachable."
fi
