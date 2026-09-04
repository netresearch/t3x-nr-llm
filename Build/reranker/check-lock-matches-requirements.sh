#!/usr/bin/env bash
#
# The image installs from requirements.lock, never from requirements.txt. So a
# bump to requirements.txt alone changes NOTHING about what is built — it only
# makes the two files disagree, silently, in the direction where the repository
# claims a version it does not ship.
#
# That is not hypothetical: bad803c9 raised sentence-transformers to 5.7.0 and
# left the lock at 5.6.1, and every image built between that commit and the one
# adding this check installed 5.6.1 while the repository said otherwise. Nothing
# noticed, because no workflow builds or reads the reranker at all.
#
# This is the cheapest gate that would have caught it: for every direct pin in
# requirements.txt, assert the lock pins the SAME version. It needs no Docker,
# no network and no Python — it does not verify the resolution, only that the
# two files are talking about the same versions.
#
# Names are normalised per PEP 503 (lowercase, runs of -_. collapsed to -),
# because pip writes `sentence_transformers` into a lock generated from a
# requirements file that says `sentence-transformers`.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REQ="$DIR/requirements.txt"
LOCK="$DIR/requirements.lock"

normalise() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[-_.]+/-/g'; }

status=0
while IFS= read -r line; do
  case "$line" in ''|'#'*) continue;; esac
  # Only exact pins are checked; the file has carried nothing else since ADR-075
  # and a range here would be a separate decision, not something to guess at.
  case "$line" in *'=='*) ;; *) continue;; esac

  name="${line%%==*}"
  want="${line#*==}"
  want="${want%% *}"
  key="$(normalise "$name")"

  found=''
  while IFS= read -r locked; do
    case "$locked" in ''|'#'*|' '*) continue;; esac
    case "$locked" in *'=='*) ;; *) continue;; esac
    lname="${locked%%==*}"
    lver="${locked#*==}"
    lver="${lver%% *}"
    lver="${lver%\\}"
    if [ "$(normalise "$lname")" = "$key" ]; then
      found="$lver"
      break
    fi
  done < "$LOCK"

  if [ -z "$found" ]; then
    printf 'reranker lock: %s is pinned in requirements.txt but absent from requirements.lock\n' "$name" >&2
    status=1
    continue
  fi

  # The PyTorch CPU index appends a local version (+cpu) that the direct pin
  # does not carry. Comparing the part before "+" keeps that legitimate.
  if [ "${found%%+*}" != "$want" ]; then
    printf 'reranker lock: %s is %s in requirements.txt but %s in requirements.lock — the image would install %s\n' \
      "$name" "$want" "$found" "$found" >&2
    status=1
  fi
done < "$REQ"

if [ "$status" -eq 0 ]; then
  printf 'reranker lock: requirements.txt and requirements.lock agree\n'
else
  printf '\nRegenerate the lock — the command is in the header of requirements.lock.\n' >&2
fi

exit "$status"
