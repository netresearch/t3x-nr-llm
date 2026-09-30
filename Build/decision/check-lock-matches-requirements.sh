#!/usr/bin/env bash
#
# The image installs from requirements.lock, never from requirements.txt. So a
# bump to requirements.txt alone changes NOTHING about what is built — it only
# makes the two files disagree, silently, in the direction where the repository
# claims a version it does not ship.
#
# The reranker sidecar next door had exactly that drift (see
# Build/reranker/check-lock-matches-requirements.sh), so this sidecar carries
# the same gate from the start: for every direct pin in requirements.txt,
# assert the lock pins the SAME version. It needs no Docker, no network and no
# Python — it does not verify the resolution, only that the two files are
# talking about the same versions.
#
# Names are normalised per PEP 503 (lowercase, runs of -_. collapsed to -),
# because the lock carries wheel-filename spellings.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REQ="$DIR/requirements.txt"
LOCK="$DIR/requirements.lock"

normalise() {
  local name="$1"
  printf '%s' "$name" | tr '[:upper:]' '[:lower:]' | sed -E 's/[-_.]+/-/g'
}

status=0
while IFS= read -r line; do
  case "$line" in ''|'#'*) continue;; *) ;; esac
  # Only exact pins are checked; a range here would be a separate decision,
  # not something to guess at.
  case "$line" in *'=='*) ;; *) continue;; esac

  name="${line%%==*}"
  want="${line#*==}"
  want="${want%% *}"
  key="$(normalise "$name")"

  found=''
  while IFS= read -r locked; do
    case "$locked" in ''|'#'*|' '*) continue;; *) ;; esac
    case "$locked" in *'=='*) ;; *) continue;; esac
    lname="${locked%%==*}"
    lver="${locked#*==}"
    lver="${lver%% *}"
    lver="${lver%\\}"
    if [[ "$(normalise "$lname")" == "$key" ]]; then
      found="$lver"
      break
    fi
  done < "$LOCK"

  if [[ -z "$found" ]]; then
    printf 'decision lock: %s is pinned in requirements.txt but absent from requirements.lock\n' "$name" >&2
    status=1
    continue
  fi

  # The PyTorch CPU index appends a local version (+cpu) that the direct pin
  # does not carry. Comparing the part before "+" keeps that legitimate.
  if [[ "${found%%+*}" != "$want" ]]; then
    printf 'decision lock: %s is %s in requirements.txt but %s in requirements.lock — the image would install %s\n' \
      "$name" "$want" "$found" "$found" >&2
    status=1
  fi
done < "$REQ"

if [[ "$status" -eq 0 ]]; then
  printf 'decision lock: requirements.txt and requirements.lock agree\n'
else
  printf '\nRegenerate the lock — the command is in the header of requirements.lock.\n' >&2
fi

exit "$status"
