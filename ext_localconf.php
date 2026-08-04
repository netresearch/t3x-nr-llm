<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Form\Element\ModelIdElement;
use Netresearch\NrLlm\Form\FieldWizard\ModelConstraintsWizard;
use Netresearch\NrLlm\Hook\ProviderEndpointNormalizationHook;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') || die();

(static function (): void {
    // Cache configuration (also in Configuration/Caching.php for TYPO3 v14+)
    // No backend specified — TYPO3 uses the instance's default cache backend,
    // which respects Redis/Valkey/Memcached if configured by the admin.
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] ??= [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['nrllm'],
    ];

    // Short-lived reachability cache (overview provider dots).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_reachability'] ??= [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ];

    // Per-provider circuit breaker state (ADR-063). The store writes its own
    // per-entry lifetime; this default applies only to entries written without.
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_circuit'] ??= [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 300,
        ],
        'groups' => ['nrllm'],
    ];

    // Short-lived provider health snapshot (ADR-063).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_health'] ??= [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ];

    // Request idempotency store (ADR-063). The middleware writes its own
    // per-entry TTL (the idempotency window).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_idempotency'] ??= [
        'frontend' => VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['nrllm'],
    ];

    // Register custom TCA renderType for model_id field with API fetch
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1741427200] = [
        'nodeName' => 'modelIdWithFetch',
        'priority' => 40,
        'class' => ModelIdElement::class,
    ];

    // Register field wizard for model constraint detection on configuration form
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1741427201] = [
        'nodeName' => 'modelConstraintsWizard',
        'priority' => 40,
        'class' => ModelConstraintsWizard::class,
    ];

    // Normalize tx_nrllm_provider.endpoint_url to the adapter's canonical base URL
    // when a provider is created/edited through the TCA record editor, so the
    // manual write path stores a working base URL just like the Setup Wizard (#300).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = ProviderEndpointNormalizationHook::class;

    // Dedicated dashboard widget group for the agentic / governance / telemetry
    // widgets, so they do not scatter into the built-in 'general' group. Inert
    // without typo3/cms-dashboard installed (the array is simply never read).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['dashboard']['widgetGroups']['nrllm'] ??= [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widgetGroup.nrllm.title',
    ];
})();
