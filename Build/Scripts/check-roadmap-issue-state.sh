#!/usr/bin/env bash
#
# Fails when ROADMAP.md names an issue that is already closed.
#
# WHY THIS EXISTS. The file asserts which issues are open, and that assertion
# has been wrong three times (2e2d5431, 90780db1, #804). The cause is not
# carelessness: the drift is produced by SIBLING merges, not by the pull request
# that touches ROADMAP.md — 90780db1's own message says "sixteen sibling merges
# changed that answer while it waited". A check that only ran on pull requests
# touching the file would therefore never see it, which is why the caller also
# runs this on a schedule.
#
# WHY UNAUTHENTICATED. The shared script-check workflow deliberately runs with
# no GH_TOKEN and forwards no secrets. This repository is public, so the issues
# endpoint answers anonymously; the rate limit is 60/hour per IP.
#
# EXIT CODES. 0 = every referenced issue is open. 1 = at least one is closed.
# 2 = the state could not be determined (rate limit, network). Two is a WARNING
# the caller lets pass, because a shared runner IP can exhaust the anonymous
# quota through no fault of ours — and a check that fails on that would be
# turned off within a week. The distinction is the point: "wrong" and "could not
# ask" must not look the same.

set -uo pipefail

ROADMAP="${1:-ROADMAP.md}"
REPO="${GITHUB_REPOSITORY:-netresearch/t3x-nr-llm}"
API="https://api.github.com/repos/${REPO}/issues"

if [ ! -f "$ROADMAP" ]; then
    echo "::error::$ROADMAP not found"
    exit 1
fi

# ENTRIES only, not every mention. The file's own rule is "every item in the two
# sections below is an open GitHub issue", and an item is a bullet of the form
#   - **[#123](url) — title.**
# Prose elsewhere cites closed issues on purpose — #804 removed an entry and
# kept a sentence saying which record closed it. A grep over every "#123" reads
# that sentence as a claim that the issue is open, which it is not. Scoping to
# the entry form is what separates the assertion from the history.
numbers=$(grep -oE '^- \*\*\[#[0-9]{2,6}\]' "$ROADMAP" | grep -oE '[0-9]{2,6}' | sort -un)

if [ -z "$numbers" ]; then
    echo "No issue references in $ROADMAP."
    exit 0
fi

closed=""
unknown=""
open_count=0

for n in $numbers; do
    body=$(curl -sS --max-time 20 -w '\n%{http_code}' "$API/$n" 2>/dev/null)
    code=$(printf '%s' "$body" | tail -n1)
    json=$(printf '%s' "$body" | sed '$d')

    case "$code" in
        200)
            state=$(printf '%s' "$json" | grep -m1 -oE '"state"[[:space:]]*:[[:space:]]*"[a-z]+"' | grep -oE '[a-z]+"$' | tr -d '"')
            if [ "$state" = "closed" ]; then
                closed="$closed $n"
            elif [ "$state" = "open" ]; then
                open_count=$((open_count + 1))
            else
                unknown="$unknown $n"
            fi
            ;;
        403|429)
            # Anonymous quota exhausted on this runner's IP.
            unknown="$unknown $n"
            ;;
        404)
            echo "::error::$ROADMAP references #$n, which does not exist in $REPO."
            closed="$closed $n"
            ;;
        *)
            unknown="$unknown $n"
            ;;
    esac
done

if [ -n "$closed" ]; then
    for n in $closed; do
        echo "::error file=$ROADMAP::#$n is closed but $ROADMAP still lists it as open work."
    done
    echo "A closed item in the roadmap sends the next reader to re-derive a decision that already shipped."
    exit 1
fi

if [ -n "$unknown" ]; then
    echo "::warning file=$ROADMAP::Could not determine the state of:$unknown (rate limit or network). $open_count reference(s) verified open."
    exit 2
fi

echo "All $open_count issue reference(s) in $ROADMAP are open."
exit 0
