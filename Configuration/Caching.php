<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
