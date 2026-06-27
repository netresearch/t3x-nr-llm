<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source',
        'label' => 'title',
        'label_alt' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'title ASC',
        'searchFields' => 'title,url,ref',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Skill.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;core.form.tabs:general,
                    title,
                    type,
                    url,
                    ref,
                --div--;core.form.tabs:metadata,
                    pinned_sha,
                    sync_status,
                    sync_error,
                    last_synced,
                --div--;core.form.tabs:access,
                    enabled,
                    hidden,
            ',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'trim' => true,
                'required' => true,
            ],
        ],
        'type' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.type.single_file', 'value' => 'single_file'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.type.repo', 'value' => 'repo'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.type.marketplace', 'value' => 'marketplace'],
                ],
                'default' => 'single_file',
                'required' => true,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'trim' => true,
                'required' => true,
            ],
        ],
        'ref' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.ref',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'trim' => true,
            ],
        ],
        'pinned_sha' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.pinned_sha',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'readOnly' => true,
            ],
        ],
        'sync_status' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status.never_synced', 'value' => 'never_synced'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status.syncing', 'value' => 'syncing'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status.ok', 'value' => 'ok'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status.partial', 'value' => 'partial'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_status.error', 'value' => 'error'],
                ],
                'default' => 'never_synced',
                'readOnly' => true,
            ],
        ],
        'sync_error' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.sync_error',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'last_synced' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.last_synced',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'enabled' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill_source.enabled',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
    ],
];
