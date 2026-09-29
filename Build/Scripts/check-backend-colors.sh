#!/bin/sh
#
# Copyright (c) 2025-2026 Netresearch DTT GmbH
# SPDX-License-Identifier: GPL-2.0-or-later
#
# `composer ci:test:colors`, also run by the captainhook pre-commit hook:
# the backend colour check (`npm run lint:colors`). It runs on Node.js and
# reads JavaScript, CSS and HTML with espree, css-tree and parse5 from
# node_modules, so it needs npm on PATH and `npm ci` done in the repository
# root. The post-checkout and post-merge hooks run `npm ci` next to
# `composer install`; this guard names what is missing instead of letting
# npm fail with a module-resolution error.

set -eu
cd "$(dirname "$0")/../.."

if ! command -v npm >/dev/null 2>&1; then
    echo "ci:test:colors: npm is not on PATH. The colour check runs on Node.js (package.json engines: node >=22.18); install Node.js with npm, then run 'npm ci' in the repository root." >&2
    exit 1
fi

for package in espree css-tree parse5; do
    if [ ! -d "node_modules/$package" ]; then
        echo "ci:test:colors: node_modules/$package is missing. Run 'npm ci' in the repository root (the post-checkout and post-merge hooks run it after the next checkout or merge)." >&2
        exit 1
    fi
done

exec npm run lint:colors
