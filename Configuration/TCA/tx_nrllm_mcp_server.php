<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrVault\TCA\VaultFieldHelper;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server',
        'label' => 'name',
        'label_alt' => 'identifier,url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'name ASC',
        'searchFields' => 'identifier,name,description,url',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/module-nrllm-tool.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    identifier,
                    name,
                    description,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.connection,
                    url,
                    auth_placement,
                    auth_header_name,
                    auth_credential,
                    data_class,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.metadata,
                    import_status,
                    import_error,
                    last_imported,
                    tool_count,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
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
        // Prefix of every imported tool name and of the group mcp_<identifier>,
        // so an edit renames the whole tool namespace of this server.
        'identifier' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 32,
                'trim' => true,
                'eval' => 'alphanum_x,lower,unique',
                'required' => true,
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'trim' => true,
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.url',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.url.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'trim' => true,
                'required' => true,
            ],
        ],
        'auth_credential' => VaultFieldHelper::getSecureFieldConfig(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_credential',
            [
                'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_credential.description',
                'size' => 50,
            ],
        ),
        'auth_placement' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_placement',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_placement.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_placement.bearer', 'value' => 'bearer'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_placement.header', 'value' => 'header'],
                ],
                'default' => 'bearer',
                'required' => true,
            ],
        ],
        'auth_header_name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_header_name',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.auth_header_name.description',
            'displayCond' => 'FIELD:auth_placement:=:header',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 190,
                'trim' => true,
            ],
        ],
        // Egress ceiling every tool of this server is measured against
        // (ADR-094). No default — an operator has to declare it, and until then
        // the server contributes nothing.
        'data_class' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.data_class',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.data_class.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
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
        'import_status' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status.never_imported', 'value' => 'never_imported'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status.importing', 'value' => 'importing'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status.ok', 'value' => 'ok'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status.partial', 'value' => 'partial'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_status.error', 'value' => 'error'],
                ],
                'default' => 'never_imported',
                'readOnly' => true,
            ],
        ],
        'import_error' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.import_error',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'last_imported' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.last_imported',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'tool_count' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.tool_count',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'readOnly' => true,
            ],
        ],
        'enabled' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.enabled',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_mcp_server.enabled.description',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
];
