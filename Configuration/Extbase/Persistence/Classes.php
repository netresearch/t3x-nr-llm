<?php

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;

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
            'providerUid' => [
                'fieldName' => 'provider_uid',
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
            'modelUid' => [
                'fieldName' => 'model_uid',
            ],
            'llmModel' => [
                'fieldName' => 'model_uid',
            ],
        ],
    ],
];
