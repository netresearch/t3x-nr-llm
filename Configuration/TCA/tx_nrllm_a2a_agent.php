<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardConfirmItems;
use Netresearch\NrVault\TCA\VaultFieldHelper;

$ll = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_a2a_agent';

$limit = static fn (string $field, int $default, int $min, int $max): array => [
    'label'       => $ll . '.' . $field,
    'description' => $ll . '.' . $field . '.description',
    'config'      => [
        'type'    => 'number',
        'default' => $default,
        'range'   => ['lower' => $min, 'upper' => $max],
    ],
];

$readOnly = static fn (string $field, array $config): array => [
    'label'  => $ll . '.' . $field,
    'config' => ['readOnly' => true] + $config,
];

/*
 * A remote A2A agent (spec 051 A2A-C1). The operator decides its trust:
 * data_class has no default and the record does nothing without one, enabled
 * starts at 0 and requires_approval at 1, exactly as tx_nrllm_mcp_server. The
 * card import (A2A-C2) fills the read-only fields and never touches these.
 */
return [
    'ctrl' => [
        'title'            => $ll,
        'label'            => 'title',
        'label_alt'        => 'identifier,card_url',
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'delete'           => 'deleted',
        'default_sortby'   => 'title ASC',
        'typeicon_classes' => ['default' => 'nrllm-record-a2a-agent'],
        'rootLevel'        => -1,
        // The record decides where a credential goes and what leaves the
        // installation; only administrators edit it (spec 051 A2A-C1, A2A-C2).
        'adminOnly' => true,
        'security'  => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    identifier,
                    title,
                    description,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.connection,
                    card_url,
                    credential,
                    data_class,
                    requires_approval,
                --div--;' . $ll . '.tab.card,
                    card_changed,
                    card_confirm,
                    confirmed_interface,
                    interface_url,
                    interface_tenant,
                    card_capabilities,
                    card_fetched,
                    card_etag,
                    import_error,
                    card_json,
                --div--;' . $ll . '.tab.limits,
                    max_wait_seconds,
                    min_poll_interval_seconds,
                    max_poll_interval_seconds,
                    max_response_bytes,
                    max_delegations_per_run,
                    connect_timeout_seconds,
                    read_timeout_seconds,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    enabled,
',
        ],
    ],
    'columns' => [
        'identifier' => [
            'label'       => $ll . '.identifier',
            'description' => $ll . '.identifier.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 30,
                'max'      => 32,
                'trim'     => true,
                'eval'     => 'alphanum_x,lower,unique',
                'required' => true,
            ],
        ],
        'title' => [
            'label'  => $ll . '.title',
            'config' => [
                'type'     => 'input',
                'size'     => 50,
                'max'      => 255,
                'trim'     => true,
                'required' => true,
            ],
        ],
        'description' => [
            'label'  => $ll . '.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'card_url' => [
            'label'       => $ll . '.card_url',
            'description' => $ll . '.card_url.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 50,
                'max'      => 2048,
                'trim'     => true,
                'required' => true,
            ],
        ],
        'credential' => array_replace_recursive(
            VaultFieldHelper::getSecureFieldConfig(
                $ll . '.credential',
                [
                    'description' => $ll . '.credential.description',
                    'size'        => 50,
                ],
            ),
            ['config' => ['searchable' => false]],
        ),
        // What leaves the installation towards this agent. No default: until
        // an operator declares it, the record sends nothing (A2A-C1).
        'data_class' => [
            'label'       => $ll . '.data_class',
            'description' => $ll . '.data_class.description',
            'config'      => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.publicContent', 'value' => 'publicContent'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.editorContent', 'value' => 'editorContent'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.sourceCode', 'value' => 'sourceCode'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.internalConfiguration', 'value' => 'internalConfiguration'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.systemDiagnostics', 'value' => 'systemDiagnostics'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.secretAdjacent', 'value' => 'secretAdjacent'],
                ],
                'required' => true,
            ],
        ],
        'requires_approval' => [
            'label'       => $ll . '.requires_approval',
            'description' => $ll . '.requires_approval.description',
            'config'      => [
                'type'    => 'check',
                'default' => 1,
            ],
        ],
        'enabled' => [
            'label'  => $ll . '.enabled',
            'config' => [
                'type'    => 'check',
                'default' => 0,
            ],
        ],
        'card_changed' => [
            'label'       => $ll . '.card_changed',
            'description' => $ll . '.card_changed.description',
            'config'      => [
                'type'     => 'check',
                'readOnly' => true,
                'default'  => 0,
            ],
        ],
        // The value of the one item is the digest of the card the form shows;
        // the save hook confirms only when it still matches the stored card.
        'card_confirm' => [
            'label'       => $ll . '.card_confirm',
            'description' => $ll . '.card_confirm.description',
            'displayCond' => 'FIELD:card_changed:=:1',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'items'         => [['label' => $ll . '.card_confirm.none', 'value' => '']],
                'itemsProcFunc' => A2aCardConfirmItems::class . '->addItems',
                'default'       => '',
            ],
        ],
        'confirmed_interface'       => $readOnly('confirmed_interface', ['type' => 'input', 'size' => 50, 'searchable' => false]),
        'interface_url'             => $readOnly('interface_url', ['type' => 'input', 'size' => 50]),
        'interface_tenant'          => $readOnly('interface_tenant', ['type' => 'input', 'size' => 30, 'searchable' => false]),
        'card_capabilities'         => $readOnly('card_capabilities', ['type' => 'input', 'size' => 50, 'searchable' => false]),
        'card_fetched'              => $readOnly('card_fetched', ['type' => 'datetime', 'nullable' => true, 'searchable' => false]),
        'card_etag'                 => $readOnly('card_etag', ['type' => 'input', 'size' => 30, 'searchable' => false]),
        'import_error'              => $readOnly('import_error', ['type' => 'text', 'rows' => 3, 'searchable' => false]),
        'card_json'                 => $readOnly('card_json', ['type' => 'text', 'rows' => 10, 'fixedFont' => true, 'searchable' => false]),
        'max_wait_seconds'          => $limit('max_wait_seconds', 3600, 1, 604800),
        'min_poll_interval_seconds' => $limit('min_poll_interval_seconds', 5, 1, 3600),
        'max_poll_interval_seconds' => $limit('max_poll_interval_seconds', 300, 1, 86400),
        'max_response_bytes'        => $limit('max_response_bytes', 1048576, 1024, 67108864),
        'max_delegations_per_run'   => $limit('max_delegations_per_run', 3, 0, 100),
        'connect_timeout_seconds'   => $limit('connect_timeout_seconds', 5, 1, 60),
        'read_timeout_seconds'      => $limit('read_timeout_seconds', 30, 1, 600),
    ],
];
