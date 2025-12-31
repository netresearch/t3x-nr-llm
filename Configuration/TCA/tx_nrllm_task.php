<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task',
        'label' => 'name',
        'label_alt' => 'identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'default_sortby' => 'category ASC, sorting ASC, name ASC',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Task.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;identity,
                    --palette--;;settings,
                    prompt_template,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.tab.input_output,
                    --palette--;;input,
                    --palette--;;output,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    --palette--;;status,
            ',
        ],
    ],
    'palettes' => [
        'identity' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.identity',
            'showitem' => 'identifier, --linebreak--, name, --linebreak--, description',
        ],
        'settings' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.settings',
            'showitem' => 'category, configuration_uid',
        ],
        'input' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.palette.input',
            'showitem' => 'input_type, --linebreak--, input_source',
        ],
        'output' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.palette.output',
            'showitem' => 'output_format',
        ],
        'status' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.status',
            'showitem' => 'is_active, is_system, hidden',
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
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'eval' => 'trim,alphanum_x,lower',
                'required' => true,
                'placeholder' => 'analyze-syslog',
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'category' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.category',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Log Analysis', 'value' => 'log_analysis'],
                    ['label' => 'Content Operations', 'value' => 'content'],
                    ['label' => 'System Health', 'value' => 'system'],
                    ['label' => 'Developer Assistance', 'value' => 'developer'],
                    ['label' => 'General', 'value' => 'general'],
                ],
                'default' => 'general',
            ],
        ],
        'configuration_uid' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.configuration_uid',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.configuration_uid.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_configuration',
                'foreign_table_where' => 'AND {#tx_nrllm_configuration}.{#deleted} = 0 ORDER BY tx_nrllm_configuration.name',
                'items' => [
                    ['label' => '-- Use Default Configuration --', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'prompt_template' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.prompt_template',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.prompt_template.description',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 15,
                'required' => true,
                'placeholder' => 'Analyze the following log entries and provide a summary of issues found:\n\n{{input}}',
            ],
        ],
        'input_type' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.input_type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Manual Input', 'value' => 'manual'],
                    ['label' => 'System Log (sys_log)', 'value' => 'syslog'],
                    ['label' => 'Deprecation Log', 'value' => 'deprecation_log'],
                    ['label' => 'Database Table', 'value' => 'table'],
                    ['label' => 'File', 'value' => 'file'],
                ],
                'default' => 'manual',
            ],
        ],
        'input_source' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.input_source',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.input_source.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'placeholder' => '{"table": "sys_log", "limit": 100, "where": "error > 0"}',
                'searchable' => false,
            ],
        ],
        'output_format' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.output_format',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Markdown', 'value' => 'markdown'],
                    ['label' => 'JSON', 'value' => 'json'],
                    ['label' => 'Plain Text', 'value' => 'plain'],
                    ['label' => 'HTML', 'value' => 'html'],
                ],
                'default' => 'markdown',
            ],
        ],
        'is_active' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.is_active',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'is_system' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.is_system',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.is_system.description',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
];
