<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$ll = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_a2a_client';

/*
 * A remote client of the A2A server role (spec 051 A2A-SEC1, A2A-SEC3,
 * ADR-280). Only administrators edit it. The token is never a form field:
 * `nrllm:a2a:client:token <uid>` issues it and shows it once; the record
 * keeps its public prefix and its hash. enabled starts at 0.
 */
return [
    'ctrl' => [
        'title'            => $ll,
        'label'            => 'title',
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'delete'           => 'deleted',
        'default_sortby'   => 'title ASC',
        'typeicon_classes' => ['default' => 'nrllm-record-a2a-agent'],
        'rootLevel'        => -1,
        'adminOnly'        => true,
        'security'         => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    title,
                    description,
                    token_prefix,
                    token_issued,
                --div--;' . $ll . '.tab.identity,
                    be_user,
                    workspace,
                    profiles,
                    task_limit,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    enabled,
                    expires,
',
        ],
    ],
    'columns' => [
        'title' => [
            'label'  => $ll . '.title',
            'config' => ['type' => 'input', 'size' => 50, 'max' => 255, 'trim' => true, 'required' => true],
        ],
        'description' => [
            'label'  => $ll . '.description',
            'config' => ['type' => 'text', 'cols' => 40, 'rows' => 3],
        ],
        'token_prefix' => [
            'label'       => $ll . '.token_prefix',
            'description' => $ll . '.token_prefix.description',
            'config'      => ['type' => 'input', 'size' => 30, 'readOnly' => true, 'searchable' => false],
        ],
        'token_issued' => [
            'label'  => $ll . '.token_issued',
            'config' => ['type' => 'datetime', 'readOnly' => true, 'nullable' => true, 'searchable' => false],
        ],
        'be_user' => [
            'label'       => $ll . '.be_user',
            'description' => $ll . '.be_user.description',
            'config'      => [
                'type'                => 'select',
                'renderType'          => 'selectSingle',
                'foreign_table'       => 'be_users',
                'foreign_table_where' => 'AND {#be_users}.{#admin} = 0 ORDER BY {#be_users}.{#username}',
                'items'               => [['label' => '', 'value' => 0]],
                'default'             => 0,
                'required'            => true,
            ],
        ],
        'workspace' => [
            'label'       => $ll . '.workspace',
            'description' => $ll . '.workspace.description',
            'config'      => [
                'type'    => 'number',
                'default' => 0,
                'range'   => ['lower' => 0],
            ],
        ],
        'profiles' => [
            'label'       => $ll . '.profiles',
            'description' => $ll . '.profiles.description',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectMultipleSideBySide',
                'foreign_table' => 'tx_nrllm_a2a_profile',
                'size'          => 6,
                'maxitems'      => 100,
            ],
        ],
        'task_limit' => [
            'label'       => $ll . '.task_limit',
            'description' => $ll . '.task_limit.description',
            'config'      => ['type' => 'number', 'default' => 4, 'range' => ['lower' => 1, 'upper' => 1000]],
        ],
        'enabled' => [
            'label'  => $ll . '.enabled',
            'config' => ['type' => 'check', 'default' => 0],
        ],
        'expires' => [
            'label'       => $ll . '.expires',
            'description' => $ll . '.expires.description',
            'config'      => ['type' => 'datetime', 'default' => 0, 'searchable' => false],
        ],
    ],
];
