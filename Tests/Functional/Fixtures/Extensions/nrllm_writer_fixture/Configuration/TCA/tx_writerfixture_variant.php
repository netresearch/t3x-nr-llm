<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table whose type column declares no default: the record type comes from
 * core's own fallback ("0", then "1"). create_record_draft writes that type,
 * so the record carries it instead of the column's database default ('') and
 * the read-back finds what was checked (ADR-197).
 */
return [
    'ctrl' => [
        'title'         => 'Fixture variant',
        'label'         => 'title',
        'tstamp'        => 'tstamp',
        'crdate'        => 'crdate',
        'delete'        => 'deleted',
        'type'          => 'variant',
        'enablecolumns' => ['disabled' => 'hidden'],
    ],
    'types' => [
        '1' => ['showitem' => 'title, variant'],
    ],
    'columns' => [
        'hidden' => [
            'label'  => 'Hidden',
            'config' => ['type' => 'check'],
        ],
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input', 'max' => 100],
        ],
        'variant' => [
            'label'  => 'Variant',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [['label' => 'Standard', 'value' => '1']],
            ],
        ],
    ],
];
