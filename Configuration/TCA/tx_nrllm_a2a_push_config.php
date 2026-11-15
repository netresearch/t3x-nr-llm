<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$ll = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_a2a_push_config';

/*
 * A push notification config of the A2A server role (spec 051 A2A-PUSH1,
 * A2A-PUSH5, ADR-285), shown read-only to administrators so a config that
 * a receiver refused (auth_failed) or that stopped is visible. A client
 * writes it over A2A only. The token and the credential are sealed and are
 * never a field here.
 */
return [
    'ctrl' => [
        'title'            => $ll,
        'label'            => 'url',
        'label_alt'        => 'state',
        'label_alt_force'  => true,
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'default_sortby'   => 'tstamp DESC',
        'typeicon_classes' => ['default' => 'nrllm-record-a2a-agent'],
        'rootLevel'        => 1,
        'adminOnly'        => true,
        'readOnly'         => true,
        'hideTable'        => false,
        'security'         => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'url, state, reason, config_id, task_uid, owner_client_uid',
        ],
    ],
    'columns' => [
        'url' => [
            'label'  => $ll . '.url',
            'config' => ['type' => 'input', 'size' => 50, 'readOnly' => true],
        ],
        'state' => [
            'label'  => $ll . '.state',
            'config' => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'readOnly'   => true,
                'items'      => [
                    ['label' => $ll . '.state.active', 'value' => 'active'],
                    ['label' => $ll . '.state.auth_failed', 'value' => 'auth_failed'],
                    ['label' => $ll . '.state.stopped', 'value' => 'stopped'],
                ],
            ],
        ],
        'reason' => [
            'label'  => $ll . '.reason',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'config_id' => [
            'label'  => $ll . '.config_id',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'task_uid' => [
            'label'  => $ll . '.task_uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'owner_client_uid' => [
            'label'  => $ll . '.owner_client_uid',
            'config' => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'foreign_table' => 'tx_nrllm_a2a_client',
                'readOnly'      => true,
                'items'         => [['label' => '', 'value' => 0]],
            ],
        ],
    ],
];
