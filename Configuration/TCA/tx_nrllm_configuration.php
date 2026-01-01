<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration',
        'label' => 'name',
        'label_alt' => 'identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;identity,
                    --palette--;;provider,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.parameters,
                    --palette--;;parameters,
                    system_prompt,
                    options,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.limits,
                    --palette--;;limits,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.access,
                    allowed_groups,
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
        'provider' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.provider',
            'showitem' => 'model_selection_mode, --linebreak--, model_uid, model_selection_criteria, --linebreak--, translator',
        ],
        'parameters' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.parameters',
            'showitem' => 'temperature, max_tokens, timeout, --linebreak--, top_p, frequency_penalty, presence_penalty',
        ],
        'limits' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.limits',
            'showitem' => 'max_requests_per_day, max_tokens_per_day, max_cost_per_day',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'eval' => 'trim,alphanum_x,lower,unique',
                'required' => true,
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        'model_selection_mode' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_mode',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_mode.description',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_mode.fixed', 'value' => 'fixed'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_mode.criteria', 'value' => 'criteria'],
                ],
                'default' => 'fixed',
            ],
        ],
        'model_uid' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_uid',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_uid.description',
            'displayCond' => 'FIELD:model_selection_mode:=:fixed',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_model',
                'foreign_table_where' => 'AND {#tx_nrllm_model}.{#hidden} = 0 AND {#tx_nrllm_model}.{#deleted} = 0 ORDER BY tx_nrllm_model.name',
                'items' => [
                    ['label' => '-- Select Model --', 'value' => 0],
                ],
                'default' => 0,
                'minitems' => 0,
            ],
        ],
        'model_selection_criteria' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_criteria',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.model_selection_criteria.description',
            'displayCond' => 'FIELD:model_selection_mode:=:criteria',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.chat', 'value' => 'cap:chat', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.completion', 'value' => 'cap:completion', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.vision', 'value' => 'cap:vision', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.tools', 'value' => 'cap:tools', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.json_mode', 'value' => 'cap:json_mode', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.streaming', 'value' => 'cap:streaming', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.embeddings', 'value' => 'cap:embeddings', 'group' => 'capabilities'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_model.capabilities.audio', 'value' => 'cap:audio', 'group' => 'capabilities'],
                    ['label' => 'OpenAI', 'value' => 'adapter:openai', 'group' => 'adapters'],
                    ['label' => 'Anthropic (Claude)', 'value' => 'adapter:anthropic', 'group' => 'adapters'],
                    ['label' => 'Google (Gemini)', 'value' => 'adapter:gemini', 'group' => 'adapters'],
                    ['label' => 'OpenRouter', 'value' => 'adapter:openrouter', 'group' => 'adapters'],
                    ['label' => 'Mistral', 'value' => 'adapter:mistral', 'group' => 'adapters'],
                    ['label' => 'Groq', 'value' => 'adapter:groq', 'group' => 'adapters'],
                    ['label' => 'Ollama', 'value' => 'adapter:ollama', 'group' => 'adapters'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.prefer_lowest_cost', 'value' => 'pref:lowest_cost', 'group' => 'preferences'],
                ],
                'itemGroups' => [
                    'capabilities' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.criteria.group.capabilities',
                    'adapters' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.criteria.group.adapters',
                    'preferences' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.criteria.group.preferences',
                ],
                'default' => '',
            ],
        ],
        'translator' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.translator',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.translator.description',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.system_prompt',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 8,
                'eval' => 'trim',
                'enableRichtext' => false,
            ],
        ],
        'temperature' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.temperature',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.temperature.description',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens.description',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.top_p',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.frequency_penalty',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.presence_penalty',
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
        'timeout' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.timeout',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.timeout.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => [
                    'lower' => 0,
                    'upper' => 600,
                ],
                'default' => 0,
            ],
        ],
        'options' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.options',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.options.description',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'eval' => 'trim',
                'placeholder' => '{"stop_sequences": ["\\n\\n"]}',
            ],
        ],
        'max_requests_per_day' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_requests_per_day',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_requests_per_day.description',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_tokens_per_day',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.max_cost_per_day',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.is_active',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'is_default' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.is_default',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'allowed_groups' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.allowed_groups',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.allowed_groups.description',
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
