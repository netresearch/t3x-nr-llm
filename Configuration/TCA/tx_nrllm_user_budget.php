<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget',
        'label' => 'be_user',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Extension.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;core.form.tabs:general,
                    be_user,
                    is_active,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.daily_limits,
                    --palette--;;daily_limits,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.monthly_limits,
                    --palette--;;monthly_limits,
                --div--;core.form.tabs:access,
                    hidden
            ',
        ],
    ],
    'palettes' => [
        'daily_limits' => [
            'showitem' => 'max_requests_per_day, max_tokens_per_day, max_cost_per_day',
        ],
        'monthly_limits' => [
            'showitem' => 'max_requests_per_month, max_tokens_per_month, max_cost_per_month',
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
        'be_user' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'foreign_table_where' => 'AND {#be_users}.{#hidden} = 0 AND {#be_users}.{#deleted} = 0 ORDER BY be_users.username',
                'minitems' => 1,
                'maxitems' => 1,
                'required' => true,
            ],
        ],
        'is_active' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.is_active',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'max_requests_per_day' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_requests_per_day',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0,
            ],
        ],
        'max_tokens_per_day' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_tokens_per_day',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0,
            ],
        ],
        'max_cost_per_day' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_cost_per_day',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0.0,
            ],
        ],
        'max_requests_per_month' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_requests_per_month',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0,
            ],
        ],
        'max_tokens_per_month' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_tokens_per_month',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0,
            ],
        ],
        'max_cost_per_month' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.max_cost_per_month',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_user_budget.unlimited_hint',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 10,
                'range' => ['lower' => 0],
                'default' => 0.0,
            ],
        ],
    ],
];
