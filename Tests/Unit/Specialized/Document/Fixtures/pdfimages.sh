#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
if [ "$1" = '-v' ]; then
    exit 0
fi
printf '%s\n' "$@" > "$NRLLM_POPPLER_TEST_OUTPUT/image-argv.log"
printf 'page num type width height\n---------------------------\n 5 0 image 1 1\n 2 0 image 1 1\n 5 1 image 1 1\n malformed line\n'
