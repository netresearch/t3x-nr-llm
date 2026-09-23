<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table without a "disabled" enable column: nothing this extension writes
 * may be visible before a human unhides it, so create_record_draft refuses it
 * (ADR-197).
 */
return [
    'ctrl' => [
        'title'  => 'Fixture plain',
        'label'  => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
    ],
    'types' => [
        '1' => ['showitem' => 'title'],
    ],
    'columns' => [
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input', 'max' => 100],
        ],
    ],
];
