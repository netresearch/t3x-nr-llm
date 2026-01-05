<?php

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Task;

/*
 * Extbase persistence configuration for nr_llm.
 *
 * Maps domain models to database tables that don't follow
 * the default Extbase naming convention.
 */
return [
    Provider::class => [
        'tableName' => 'tx_nrllm_provider',
        'properties' => [
            'cruserId' => [
                'fieldName' => 'cruser_id',
            ],
        ],
    ],
    Model::class => [
        'tableName' => 'tx_nrllm_model',
        'properties' => [
            'cruserId' => [
                'fieldName' => 'cruser_id',
            ],
            'provider' => [
                'fieldName' => 'provider_uid',
            ],
        ],
    ],
    LlmConfiguration::class => [
        'tableName' => 'tx_nrllm_configuration',
        'properties' => [
            'cruserId' => [
                'fieldName' => 'cruser_id',
            ],
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
            'allowedGroups' => [
                'fieldName' => 'allowed_groups',
            ],
        ],
    ],
    Task::class => [
        'tableName' => 'tx_nrllm_task',
        'properties' => [
            'cruserId' => [
                'fieldName' => 'cruser_id',
            ],
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
];
