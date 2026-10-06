<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table with exactly one file field, so attach_file_to_record may infer it.
 */
return [
    'ctrl' => [
        'title'  => 'Fixture cover',
        'label'  => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
    ],
    'types' => [
        '1' => ['showitem' => 'title, cover'],
    ],
    'columns' => [
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input', 'max' => 100],
        ],
        'cover' => [
            'label'  => 'Cover',
            'config' => ['type' => 'file', 'maxitems' => 1],
        ],
    ],
];
