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
        ],
    ],
];
