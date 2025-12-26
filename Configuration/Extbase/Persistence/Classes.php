<?php

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/*
 * Extbase persistence configuration for nr_llm.
 *
 * Maps domain models to database tables that don't follow
 * the default Extbase naming convention.
 */
return [
    LlmConfiguration::class => [
        'tableName' => 'tx_nrllm_configuration',
    ],
];
