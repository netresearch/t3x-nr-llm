<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * The item of an accordion or card group, shaped like bootstrap_package's
 * item tables (ADR-324): a required exclude header, a column without
 * `exclude`, rich text, a link with its title, an icon select filled by an
 * `itemsProcFunc` and a file, besides the system columns.
 */
return [
    'ctrl' => [
        'title'                    => 'Fixture item',
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
    ],
    'types' => [
        '1' => [
            'showitem' => 'parent_content, header, subheader, bodytext, link, link_title, icon, image, '
                . '--palette--;;language, --div--;Access, hidden, starttime, endtime',
        ],
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
                'foreign_table' => 'tx_childrenfixture_item',
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
        'header' => [
            'exclude' => true,
            'label'   => 'Header',
            'config'  => ['type' => 'input', 'max' => 255, 'required' => true, 'eval' => 'trim'],
        ],
        'subheader' => [
            'label'  => 'Subheader',
            'config' => ['type' => 'input', 'max' => 255],
        ],
        'bodytext' => [
            'exclude' => true,
            'label'   => 'Text',
            'config'  => ['type' => 'text', 'enableRichtext' => true],
        ],
        'link' => [
            'exclude' => true,
            'label'   => 'Link',
            'config'  => ['type' => 'link', 'allowedTypes' => ['page', 'url', 'email', 'telephone']],
        ],
        'link_title' => [
            'exclude' => true,
            'label'   => 'Link text',
            'config'  => ['type' => 'input', 'max' => 100],
        ],
        'icon' => [
            'exclude' => true,
            'label'   => 'Icon',
            'config'  => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'itemsProcFunc' => 'Vendor\\Fixture\\IconItems->items',
            ],
        ],
        'image' => [
            'exclude' => true,
            'label'   => 'Image',
            'config'  => ['type' => 'file', 'maxitems' => 1, 'allowed' => 'common-image-types'],
        ],
    ],
];
