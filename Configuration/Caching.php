<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Cache configuration for TYPO3 v14+ (auto-loaded from Configuration/Caching.php).
 * For TYPO3 v13, the same configuration is registered in ext_localconf.php.
 *
 * No backend specified — TYPO3 uses the instance's default cache backend,
 * which respects Redis/Valkey/Memcached if configured by the admin.
 */

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

return [
    'nrllm_responses' => [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['nrllm'],
    ],
];
