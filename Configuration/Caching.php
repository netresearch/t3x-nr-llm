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
    // Short-lived cache for the overview's token-free provider reachability
    // probe, so a backend page load never storms the providers.
    'nrllm_reachability' => [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ],
    // Per-provider circuit breaker state (ADR-063). Transient by design: the
    // store writes its own per-entry lifetime, so this default only applies to
    // an entry written without one. Shared across web workers via the instance
    // backend (Redis/Valkey) so one worker tripping a circuit protects them all.
    'nrllm_circuit' => [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 300,
        ],
        'groups' => ['nrllm'],
    ],
    // Short-lived provider health snapshot (ADR-063), so the opt-in fallback
    // reorder does not re-aggregate the telemetry log on every retryable hop.
    'nrllm_health' => [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ],
    // Request idempotency store (ADR-063). Serialises whole typed responses
    // (VariableFrontend), so a repeated request with the same idempotency key
    // returns the stored result instead of calling the provider again. The
    // middleware writes its own per-entry TTL (the idempotency window).
    'nrllm_idempotency' => [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['nrllm'],
    ],
];
