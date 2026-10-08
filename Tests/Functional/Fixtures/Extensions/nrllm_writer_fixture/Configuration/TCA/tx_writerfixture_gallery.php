<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * A table with two file fields shaped like EXT:news' fal_media: `media`
 * accepts images only, `attachments` anything, both are exclude fields
 * (attach_file_to_record).
 */
return [
    'ctrl' => [
        'title'                    => 'Fixture gallery',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'delete'                   => 'deleted',
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns'            => [
            'disabled' => 'hidden',
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title, media, attachments'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config'  => ['type' => 'check', 'renderType' => 'checkboxToggle'],
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
                'foreign_table' => 'tx_writerfixture_gallery',
                'default'       => 0,
            ],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough', 'default' => ''],
        ],
        'title' => [
            'label'  => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.title',
            'config' => ['type' => 'input', 'max' => 100],
        ],
        'media' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:nrllm_writer_fixture/Resources/Private/Language/locallang_tca.xlf:gallery.media',
            'config'  => ['type' => 'file', 'allowed' => 'jpg,png', 'maxitems' => 10],
        ],
        'attachments' => [
            'exclude' => true,
            'label'   => 'Attachments',
            'config'  => ['type' => 'file', 'maxitems' => 10],
        ],
    ],
];
