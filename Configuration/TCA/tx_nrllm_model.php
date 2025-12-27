<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model',
        'label' => 'name',
        'label_alt' => 'identifier,model_id',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'identifier,name,description,model_id',
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Model.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;identity,
                    provider_uid,
                    model_id,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.capabilities,
                    capabilities,
                    --palette--;;limits,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.pricing,
                    --palette--;;pricing,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;status
            ',
        ],
    ],
    'palettes' => [
        'identity' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.identity',
            'showitem' => 'identifier, name, --linebreak--, description',
        ],
        'limits' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.model_limits',
            'showitem' => 'context_length, max_output_tokens',
        ],
        'pricing' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.pricing',
            'showitem' => 'cost_input, cost_output',
        ],
        'status' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.status',
            'showitem' => 'is_active, is_default',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'eval' => 'trim,alphanum_x,lower,unique',
                'required' => true,
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        'provider_uid' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.provider_uid',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.provider_uid.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_provider',
                'foreign_table_where' => 'AND {#tx_nrllm_provider}.{#hidden} = 0 AND {#tx_nrllm_provider}.{#deleted} = 0 ORDER BY tx_nrllm_provider.name',
                'items' => [
                    ['label' => '-- Select Provider --', 'value' => 0],
                ],
                'default' => 0,
                'required' => true,
                'minitems' => 1,
            ],
        ],
        'model_id' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.model_id',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.model_id.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 150,
                'eval' => 'trim',
                'required' => true,
                'placeholder' => 'gpt-4o, claude-sonnet-4-20250514, gemini-2.0-flash, ...',
            ],
        ],
        'context_length' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.context_length',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.context_length.description',
            'config' => [
                'type' => 'number',
                'size' => 15,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'max_output_tokens' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.max_output_tokens',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.max_output_tokens.description',
            'config' => [
                'type' => 'number',
                'size' => 15,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'capabilities' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'items' => [
                    ['label' => 'Chat', 'value' => 'chat'],
                    ['label' => 'Completion', 'value' => 'completion'],
                    ['label' => 'Embeddings', 'value' => 'embeddings'],
                    ['label' => 'Vision', 'value' => 'vision'],
                    ['label' => 'Streaming', 'value' => 'streaming'],
                    ['label' => 'Tool Use', 'value' => 'tools'],
                    ['label' => 'JSON Mode', 'value' => 'json_mode'],
                    ['label' => 'Audio', 'value' => 'audio'],
                ],
                'default' => 'chat',
            ],
        ],
        'cost_input' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.cost_input',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.cost_input.description',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'cost_output' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.cost_output',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.cost_output.description',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'is_active' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.is_active',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'is_default' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.is_default',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.is_default.description',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
];
