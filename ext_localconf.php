<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    // Cache configuration (also in Configuration/Caching.php for TYPO3 v14+)
    /** @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible */
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] ??= [];
    /** @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible */
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses']['frontend'] ??=
        \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class;
    /** @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible */
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses']['backend'] ??=
        \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class;

    // Register TypoScript
    ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:nr_llm/Configuration/TypoScript/setup.typoscript"',
    );

    ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:nr_llm/Configuration/TypoScript/constants.typoscript"',
    );
})();
