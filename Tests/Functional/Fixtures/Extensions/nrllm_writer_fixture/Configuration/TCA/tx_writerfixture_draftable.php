<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table TYPO3 versions, with a file field: create_record_draft and
 * attach_file_to_record write it as a draft in a workspace (ADR-264).
 */
return [
    'ctrl' => [
        'title'         => 'Fixture draftable',
        'label'         => 'title',
        'tstamp'        => 'tstamp',
        'crdate'        => 'crdate',
        'delete'        => 'deleted',
        'versioningWS'  => true,
        'enablecolumns' => ['disabled' => 'hidden'],
    ],
    'types' => [
        '1' => ['showitem' => 'title, media'],
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
        'media' => [
            'label'  => 'Media',
            'config' => ['type' => 'file', 'allowed' => 'jpg,png', 'maxitems' => 10],
        ],
    ],
];
