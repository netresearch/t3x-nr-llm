#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
if [ "$1" = '-v' ]; then
    exit 0
fi
printf '%s\n' "$@" > "$NRLLM_POPPLER_TEST_OUTPUT/argv.log"
for argument do
    prefix="$argument"
done
case "$NRLLM_POPPLER_TEST_MODE" in
    empty) exit 0 ;;
    unreadable) : > "${prefix}-1.png"; exit 0 ;;
    malformed) printf 'bad' > "${prefix}-bad.png"; exit 0 ;;
    page) printf 'page-seven' > "${prefix}-7.png"; exit 0 ;;
    fail)
        printf 'partial' > "${prefix}-1.png"
        printf 'controlled renderer failure' >&2
        exit 23
        ;;
    flood)
        # Only this timed shell writes the pipes: printf is a shell builtin.
        # BusyBox timeout then bounds a sequential-drain regression too.
        NRLLM_POPPLER_TEST_PREFIX="$prefix"
        export NRLLM_POPPLER_TEST_PREFIX
        exec /usr/bin/timeout -k 1 8 /bin/sh -c '
            i=0
            while [ "$i" -lt 128 ]; do
                printf "%1024s" "" >&2
                i=$((i + 1))
            done
            i=0
            while [ "$i" -lt 128 ]; do
                printf "%1024s" ""
                i=$((i + 1))
            done
            printf "page-two" > "${NRLLM_POPPLER_TEST_PREFIX}-2.png"
            printf "page-one" > "${NRLLM_POPPLER_TEST_PREFIX}-1.png"
        '
        ;;
esac
printf 'page-two' > "${prefix}-2.png"
printf 'page-one' > "${prefix}-1.png"
