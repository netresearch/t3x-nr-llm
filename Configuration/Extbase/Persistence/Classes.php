<?php

declare(strict_types=1);

/*
 * Extbase persistence configuration for nr_llm.
 *
 * Maps domain models to database tables that don't follow
 * the default Extbase naming convention.
 */
return [
    \Netresearch\NrLlm\Domain\Model\LlmConfiguration::class => [
        'tableName' => 'tx_nrllm_configuration',
    ],
];
