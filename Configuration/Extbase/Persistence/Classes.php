<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UserBudget;

/*
 * Extbase persistence configuration for nr_llm.
 *
 * Maps domain models to database tables that don't follow
 * the default Extbase naming convention.
 */
return [
    Provider::class => [
        'tableName' => 'tx_nrllm_provider',
    ],
    BackendUserGroup::class => [
        'tableName' => 'be_groups',
    ],
    Skill::class => [
        'tableName' => 'tx_nrllm_skill',
    ],
    SkillSource::class => [
        'tableName' => 'tx_nrllm_skill_source',
    ],
    Model::class => [
        'tableName' => 'tx_nrllm_model',
        'properties' => [
            'provider' => [
                'fieldName' => 'provider_uid',
            ],
        ],
    ],
    LlmConfiguration::class => [
        'tableName' => 'tx_nrllm_configuration',
        'properties' => [
            'llmModel' => [
                'fieldName' => 'model_uid',
            ],
            'systemPrompt' => [
                'fieldName' => 'system_prompt',
            ],
            'maxTokens' => [
                'fieldName' => 'max_tokens',
            ],
            'topP' => [
                'fieldName' => 'top_p',
            ],
            'frequencyPenalty' => [
                'fieldName' => 'frequency_penalty',
            ],
            'presencePenalty' => [
                'fieldName' => 'presence_penalty',
            ],
            'maxRequestsPerDay' => [
                'fieldName' => 'max_requests_per_day',
            ],
            'maxTokensPerDay' => [
                'fieldName' => 'max_tokens_per_day',
            ],
            'maxCostPerDay' => [
                'fieldName' => 'max_cost_per_day',
            ],
            'isActive' => [
                'fieldName' => 'is_active',
            ],
            'isDefault' => [
                'fieldName' => 'is_default',
            ],
            // The be-group access-control MM relation. Its TCA config lives on
            // the `allowed_groups` column (type=select, MM=…begroups_mm); TYPO3
            // stores the relation count in that column while the rows live in
            // tx_nrllm_configuration_begroups_mm. Mapping `beGroups` here gives
            // the ObjectStorage its ColumnMap so Extbase hydrates the relation
            // and findAccessibleForGroups() can constrain on `beGroups.uid`.
            // Without it the relation resolves to a non-existent `be_groups`
            // column and every group-scoped query raised MissingColumnMapException.
            'beGroups' => [
                'fieldName' => 'allowed_groups',
            ],
        ],
    ],
    Task::class => [
        'tableName' => 'tx_nrllm_task',
        'properties' => [
            'configuration' => [
                'fieldName' => 'configuration_uid',
            ],
            'inputType' => [
                'fieldName' => 'input_type',
            ],
            'inputSource' => [
                'fieldName' => 'input_source',
            ],
            'outputFormat' => [
                'fieldName' => 'output_format',
            ],
            'promptTemplate' => [
                'fieldName' => 'prompt_template',
            ],
            'isActive' => [
                'fieldName' => 'is_active',
            ],
            'isSystem' => [
                'fieldName' => 'is_system',
            ],
        ],
    ],
    PromptSnippet::class => [
        'tableName' => 'tx_nrllm_promptsnippet',
        'properties' => [
            'isActive' => [
                'fieldName' => 'is_active',
            ],
        ],
    ],
    UserBudget::class => [
        'tableName' => 'tx_nrllm_user_budget',
        'properties' => [
            'beUser' => [
                'fieldName' => 'be_user',
            ],
            'maxRequestsPerDay' => [
                'fieldName' => 'max_requests_per_day',
            ],
            'maxTokensPerDay' => [
                'fieldName' => 'max_tokens_per_day',
            ],
            'maxCostPerDay' => [
                'fieldName' => 'max_cost_per_day',
            ],
            'maxRequestsPerMonth' => [
                'fieldName' => 'max_requests_per_month',
            ],
            'maxTokensPerMonth' => [
                'fieldName' => 'max_tokens_per_month',
            ],
            'maxCostPerMonth' => [
                'fieldName' => 'max_cost_per_month',
            ],
            'isActive' => [
                'fieldName' => 'is_active',
            ],
        ],
    ],
];
