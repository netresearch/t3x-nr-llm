<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    // Cache configuration is in Configuration/Caching.php (TYPO3 v14+)

    // Register TypoScript
    ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:nr_llm/Configuration/TypoScript/setup.typoscript"',
    );

    ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:nr_llm/Configuration/TypoScript/constants.typoscript"',
    );
})();
