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
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Provider.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;core.form.tabs:general,
                    --palette--;;identity,
                    adapter_type,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.connection,
                    --palette--;;connection,
                    --palette--;;request,
                    options,
                --div--;core.form.tabs:access,
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
                'eval' => 'trim,alphanum_x,lower,unique',
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
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
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
                    ['label' => 'OpenAI', 'value' => 'openai'],
                    ['label' => 'Anthropic (Claude)', 'value' => 'anthropic'],
                    ['label' => 'Google Gemini', 'value' => 'gemini'],
                    ['label' => 'OpenRouter', 'value' => 'openrouter'],
                    ['label' => 'Mistral AI', 'value' => 'mistral'],
                    ['label' => 'Groq', 'value' => 'groq'],
                    ['label' => 'Ollama (Local)', 'value' => 'ollama'],
                    ['label' => 'Azure OpenAI', 'value' => 'azure_openai'],
                    ['label' => 'Custom (OpenAI-compatible)', 'value' => 'custom'],
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
                'eval' => 'trim',
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
                'eval' => 'trim',
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
                'default' => 30,
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
                'eval' => 'trim',
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
