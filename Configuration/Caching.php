<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

return [
    'nrllm_responses' => [
        'frontend' => VariableFrontend::class,
        'backend' => SimpleFileBackend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['system'],
    ],
];
