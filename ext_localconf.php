<?php

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
