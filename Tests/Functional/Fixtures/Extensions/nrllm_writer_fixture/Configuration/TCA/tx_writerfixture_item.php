<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table with one column of every scalar type create_record_draft may set,
 * one relation it may not, record types with their own showitem lists and one
 * with `columnsOverrides`, a palette, a required column, an exclude column, an
 * authMode select and a text column with a `min` (ADR-197).
 */
return [
    'ctrl' => [
        'title'                    => 'Fixture item',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'delete'                   => 'deleted',
        'type'                     => 'kind',
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns'            => [
            'disabled'  => 'hidden',
            'starttime' => 'starttime',
            'endtime'   => 'endtime',
        ],
    ],
    'types' => [
        'note'  => ['showitem' => 'title, teaser, kind, --palette--;;timing, --div--;More, featured, contact, tone, rating, related'],
        'story' => ['showitem' => 'title, kind, --palette--;;timing, featured'],
        // Per-type configuration the DataHandler validates against
        // (`columnsOverrides`): `teaser` is required and `body` is rich text
        // only for this type.
        'event' => [
            'showitem'        => 'title, kind, teaser, body, --palette--;;timing',
            'columnsOverrides' => [
                'teaser' => ['config' => ['required' => true]],
                'body'   => ['config' => ['enableRichtext' => true]],
            ],
        ],
    ],
    'palettes' => [
        'timing' => ['showitem' => 'published_at, --linebreak--, priority'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config'  => ['type' => 'check', 'renderType' => 'checkboxToggle'],
        ],
        'starttime' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config'  => ['type' => 'datetime', 'default' => 0],
        ],
        'endtime' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config'  => ['type' => 'datetime', 'default' => 0],
        ],
        'sys_language_uid' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config'  => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label'       => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'items'         => [['label' => '', 'value' => 0]],
                'foreign_table' => 'tx_writerfixture_item',
                'default'       => 0,
            ],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough', 'default' => ''],
        ],
        'title' => [
            'label'  => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.title',
            'config' => ['type' => 'input', 'max' => 100, 'required' => true, 'eval' => 'trim'],
        ],
        'teaser' => [
            'label'  => 'Teaser',
            // `min`: the DataHandler resets a shorter value to '' without an
            // errorLog entry — the silent rewrite the read-back test relies on.
            'config' => ['type' => 'text', 'rows' => 3, 'min' => 8],
        ],
        'kind' => [
            'label'  => 'Kind',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['label' => 'Note', 'value' => 'note'],
                    ['label' => 'Story', 'value' => 'story'],
                    ['label' => 'Event', 'value' => 'event'],
                ],
                'default' => 'note',
            ],
        ],
        'body' => [
            'label'  => 'Body',
            'config' => ['type' => 'text', 'rows' => 5],
        ],
        'published_at' => [
            'label'  => 'Published at',
            'config' => ['type' => 'datetime', 'default' => 0],
        ],
        'priority' => [
            'label'  => 'Priority',
            'config' => ['type' => 'number', 'range' => ['lower' => 1, 'upper' => 5], 'default' => 0],
        ],
        'featured' => [
            'exclude' => true,
            'label'   => 'Featured',
            'config'  => ['type' => 'check', 'renderType' => 'checkboxToggle'],
        ],
        'contact' => [
            'label'  => 'Contact',
            'config' => ['type' => 'email'],
        ],
        'tone' => [
            'label'  => 'Tone',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'authMode'   => 'explicitAllow',
                'items'      => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'Calm', 'value' => 'calm'],
                    ['label' => 'Loud', 'value' => 'loud'],
                ],
            ],
        ],
        'rating' => [
            'label'  => 'Rating',
            // The DataHandler stores two decimals and clamps by ceil/floor
            // against the range, so 4.2 would come out as 4.5.
            'config' => ['type' => 'number', 'format' => 'decimal', 'range' => ['lower' => 0, 'upper' => 4.5], 'default' => 0],
        ],
        'related' => [
            'label'  => 'Related',
            'config' => ['type' => 'group', 'allowed' => 'tx_writerfixture_plain'],
        ],
    ],
];
