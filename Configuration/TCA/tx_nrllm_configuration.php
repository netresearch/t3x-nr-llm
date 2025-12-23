<?php

return [
    'ctrl' => [
        'title' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration',
        'label' => 'name',
        'label_alt' => 'identifier,provider',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'identifier,name,description,provider,model',
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LXT:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;identity,
                    --palette--;;provider,
                --div--;LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.parameters,
                    --palette--;;parameters,
                    system_prompt,
                    options,
                --div--;LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.limits,
                    --palette--;;limits,
                --div--;LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.access,
                    allowed_groups,
                --div--;LXT:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;status
            ',
        ],
    ],
    'palettes' => [
        'identity' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.identity',
            'showitem' => 'identifier, name, --linebreak--, description',
        ],
        'provider' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.provider',
            'showitem' => 'provider, model, translator',
        ],
        'parameters' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.parameters',
            'showitem' => 'temperature, max_tokens, --linebreak--, top_p, frequency_penalty, presence_penalty',
        ],
        'limits' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.limits',
            'showitem' => 'max_requests_per_day, max_tokens_per_day, max_cost_per_day',
        ],
        'status' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.status',
            'showitem' => 'is_active, is_default',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LXT:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'identifier' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.identifier',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'eval' => 'trim,required,alphanum_x,lower,unique',
            ],
        ],
        'name' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'description' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        'provider' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.provider',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'OpenAI', 'value' => 'openai'],
                    ['label' => 'Claude (Anthropic)', 'value' => 'claude'],
                    ['label' => 'Gemini (Google)', 'value' => 'gemini'],
                    ['label' => 'OpenRouter', 'value' => 'openrouter'],
                    ['label' => 'Mistral AI (EU)', 'value' => 'mistral'],
                    ['label' => 'Groq (Fast)', 'value' => 'groq'],
                ],
                'default' => 'openai',
            ],
        ],
        'model' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 100,
                'eval' => 'trim',
                'placeholder' => 'gpt-4o',
            ],
        ],
        'translator' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.translator',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.translator.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'None (use LLM)', 'value' => ''],
                    ['label' => 'DeepL', 'value' => 'deepl'],
                ],
                'default' => '',
            ],
        ],
        'system_prompt' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.system_prompt',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 8,
                'eval' => 'trim',
                'enableRichtext' => false,
            ],
        ],
        'temperature' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.temperature',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.temperature.description',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 5,
                'range' => [
                    'lower' => 0.0,
                    'upper' => 2.0,
                ],
                'default' => 0.7,
                'slider' => [
                    'step' => 0.1,
                    'width' => 200,
                ],
            ],
        ],
        'max_tokens' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens.description',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => [
                    'lower' => 1,
                    'upper' => 128000,
                ],
                'default' => 1000,
            ],
        ],
        'top_p' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.top_p',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 5,
                'range' => [
                    'lower' => 0.0,
                    'upper' => 1.0,
                ],
                'default' => 1.0,
            ],
        ],
        'frequency_penalty' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.frequency_penalty',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 5,
                'range' => [
                    'lower' => -2.0,
                    'upper' => 2.0,
                ],
                'default' => 0.0,
            ],
        ],
        'presence_penalty' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.presence_penalty',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 5,
                'range' => [
                    'lower' => -2.0,
                    'upper' => 2.0,
                ],
                'default' => 0.0,
            ],
        ],
        'options' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.options',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.options.description',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'eval' => 'trim',
                'placeholder' => '{"stop_sequences": ["\\n\\n"]}',
            ],
        ],
        'max_requests_per_day' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_requests_per_day',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_requests_per_day.description',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'max_tokens_per_day' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens_per_day',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0,
            ],
        ],
        'max_cost_per_day' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_cost_per_day',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 10,
                'range' => [
                    'lower' => 0,
                ],
                'default' => 0.00,
            ],
        ],
        'is_active' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.is_active',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'is_default' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.is_default',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'allowed_groups' => [
            'label' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.allowed_groups',
            'description' => 'LXT:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.allowed_groups.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'foreign_table_where' => 'ORDER BY be_groups.title',
                'MM' => 'tx_nrllm_configuration_begroups_mm',
                'size' => 5,
                'minitems' => 0,
                'maxitems' => 100,
            ],
        ],
    ],
];
