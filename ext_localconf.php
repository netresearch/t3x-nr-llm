<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Form\Element\ModelIdElement;
use Netresearch\NrLlm\Form\FieldWizard\ModelConstraintsWizard;
use Netresearch\NrLlm\Service\CapabilityPermissionService;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

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

    // Register TypoScript
    ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:nr_llm/Configuration/TypoScript/setup.typoscript"',
    );

    ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:nr_llm/Configuration/TypoScript/constants.typoscript"',
    );

    // Register per-capability BE group permissions. Editors see a checkbox per
    // capability on the backend group edit view; admins bypass all checks.
    // Check with CapabilityPermissionService::isAllowed() or directly via
    // $backendUser->check('custom_options', 'nrllm:capability_X').
    $capabilityIcons = [
        ModelCapability::CHAT->value => 'content-message',
        ModelCapability::COMPLETION->value => 'actions-document-new',
        ModelCapability::EMBEDDINGS->value => 'actions-database',
        ModelCapability::VISION->value => 'actions-image',
        ModelCapability::STREAMING->value => 'actions-play',
        ModelCapability::TOOLS->value => 'actions-wrench',
        ModelCapability::JSON_MODE->value => 'mimetypes-text-js',
        ModelCapability::AUDIO->value => 'mimetypes-media-audio',
    ];
    $capabilityItems = [];
    foreach (ModelCapability::cases() as $capability) {
        $key = CapabilityPermissionService::permissionKey($capability);
        $capabilityItems[$key] = [
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang_be.xlf:permissions.capability.' . $capability->value,
            $capabilityIcons[$capability->value] ?? 'actions-check',
        ];
    }
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions'][CapabilityPermissionService::PERM_NAMESPACE] = [
        'header' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_be.xlf:permissions.header',
        'items' => $capabilityItems,
    ];
})();
