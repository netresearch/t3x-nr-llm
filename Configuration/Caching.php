<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Cache configuration for TYPO3 v14+ (auto-loaded from Configuration/Caching.php).
 * For TYPO3 v13, the same configuration is registered in ext_localconf.php.
 */

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
