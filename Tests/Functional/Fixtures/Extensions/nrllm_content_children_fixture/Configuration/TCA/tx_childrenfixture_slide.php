<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A child table with a type field that declares `authMode`, as
 * bootstrap_package's carousel item does (ADR-324): a draft writes the
 * type's default, which a non-admin needs an explicit allow for.
 */
return [
    'ctrl' => [
        'title'                    => 'Fixture slide',
        'label'                    => 'header',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'delete'                   => 'deleted',
        'sortby'                   => 'sorting',
        'hideTable'                => true,
        'security'                 => ['ignorePageTypeRestriction' => true],
        'versioningWS'             => true,
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns'            => [
            'disabled'  => 'hidden',
            'starttime' => 'starttime',
            'endtime'   => 'endtime',
        ],
        'type' => 'kind',
    ],
    'types' => [
        'text'  => ['showitem' => 'parent_content, kind, header, --palette--;;language, hidden'],
        'image' => ['showitem' => 'parent_content, kind, header, --palette--;;language, hidden'],
    ],
    'palettes' => [
        'language' => ['showitem' => 'sys_language_uid, l10n_parent'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label'   => 'Hidden',
            'config'  => ['type' => 'check', 'renderType' => 'checkboxToggle'],
        ],
        'starttime' => [
            'exclude' => true,
            'label'   => 'Start',
            'config'  => ['type' => 'datetime', 'default' => 0],
        ],
        'endtime' => [
            'exclude' => true,
            'label'   => 'Stop',
            'config'  => ['type' => 'datetime', 'default' => 0],
        ],
        'sys_language_uid' => [
            'exclude' => true,
            'label'   => 'Language',
            'config'  => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label'       => 'Translation of',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'items'         => [['label' => '', 'value' => 0]],
                'foreign_table' => 'tx_childrenfixture_slide',
                'default'       => 0,
            ],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough', 'default' => ''],
        ],
        'parent_content' => [
            'exclude' => true,
            'label'   => 'Content element',
            'config'  => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'foreign_table' => 'tt_content',
                'default'       => 0,
            ],
        ],
        'kind' => [
            'label'  => 'Kind',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'authMode'   => 'explicitAllow',
                'items'      => [
                    ['label' => 'Text', 'value' => 'text'],
                    ['label' => 'Image', 'value' => 'image'],
                ],
                'default' => 'text',
            ],
        ],
        'header' => [
            'label'  => 'Header',
            'config' => ['type' => 'input', 'max' => 255, 'required' => true],
        ],
    ],
];
