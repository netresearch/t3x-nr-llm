<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    // Register cache for LLM responses
    if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] ?? null)) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] = [
            'frontend' => VariableFrontend::class,
            'backend' => SimpleFileBackend::class,
            'options' => [
                'defaultLifetime' => 3600,
            ],
            'groups' => ['system'],
        ];
    }

    // Register TypoScript
    ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:nr_llm/Configuration/TypoScript/setup.typoscript"',
    );

    ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:nr_llm/Configuration/TypoScript/constants.typoscript"',
    );
})();
