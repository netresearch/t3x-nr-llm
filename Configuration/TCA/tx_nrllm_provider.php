<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrVault\TCA\VaultFieldHelper;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider',
        'label' => 'name',
        'label_alt' => 'identifier,adapter_type',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'default_sortby' => 'name ASC',
        'searchFields' => 'identifier,name,description,adapter_type',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Provider.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;identity,
                    adapter_type,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.connection,
                    --palette--;;connection,
                    --palette--;;request,
                    options,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    is_active,
                    priority
            ',
        ],
    ],
    'palettes' => [
        'identity' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.identity',
            'showitem' => 'identifier, name, --linebreak--, description',
        ],
        'connection' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.connection',
            'showitem' => 'endpoint_url, --linebreak--, api_key, organization_id',
        ],
        'request' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.request',
            'showitem' => 'api_timeout, max_retries',
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
        'identifier' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'trim' => true,
                'eval' => 'alphanum_x,lower,unique',
                'required' => true,
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.name',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.name.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'trim' => true,
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'trim' => true,
            ],
        ],
        'adapter_type' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.description',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.openai', 'value' => 'openai'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.anthropic', 'value' => 'anthropic'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.gemini', 'value' => 'gemini'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.openrouter', 'value' => 'openrouter'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.mistral', 'value' => 'mistral'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.groq', 'value' => 'groq'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.together', 'value' => 'together'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.fireworks', 'value' => 'fireworks'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.perplexity', 'value' => 'perplexity'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.ollama', 'value' => 'ollama'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.azure_openai', 'value' => 'azure_openai'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.adapter_type.custom', 'value' => 'custom'],
                ],
                'default' => 'openai',
                'required' => true,
            ],
        ],
        'endpoint_url' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.endpoint_url',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.endpoint_url.description',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 500,
                'trim' => true,
                'placeholder' => 'Leave empty for default endpoint',
            ],
        ],
        'api_key' => VaultFieldHelper::getSecureFieldConfig(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.api_key',
            [
                'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.api_key.description',
                'size' => 50,
            ],
        ),
        'organization_id' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.organization_id',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.organization_id.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 100,
                'trim' => true,
            ],
        ],
        'api_timeout' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.api_timeout',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.api_timeout.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => [
                    'lower' => 1,
                    'upper' => 600,
                ],
                'default' => 120,
            ],
        ],
        'max_retries' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.max_retries',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.max_retries.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => [
                    'lower' => 0,
                    'upper' => 10,
                ],
                'default' => 3,
            ],
        ],
        'options' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.options',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.options.description',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'trim' => true,
                'placeholder' => '{"custom_header": "value"}',
            ],
        ],
        'is_active' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.is_active',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.is_active.description',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'priority' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.priority',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.priority.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => [
                    'lower' => 0,
                    'upper' => 100,
                ],
                'default' => 50,
                'slider' => [
                    'step' => 10,
                    'width' => 200,
                ],
            ],
        ],
    ],
];
