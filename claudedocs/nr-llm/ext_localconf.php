<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// Register cache for quota tracking
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_quota'] ?? null)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_quota'] = [
        'frontend' => VariableFrontend::class,
        'backend' => SimpleFileBackend::class,
        'options' => [
            'defaultLifetime' => 3600, // 1 hour default
        ],
    ];
}

// Register data erasure hook for GDPR compliance
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = \Netresearch\NrLlm\Hook\DataErasureHook::class;

// Register scheduler tasks
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Netresearch\NrLlm\Task\AuditCleanupTask::class] = [
    'extension' => 'nr_llm',
    'title' => 'LLM Audit Log Cleanup',
    'description' => 'Anonymizes old audit logs (30+ days) and deletes very old logs (90+ days) for GDPR compliance',
];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Netresearch\NrLlm\Task\KeyRotationReminderTask::class] = [
    'extension' => 'nr_llm',
    'title' => 'LLM API Key Rotation Reminder',
    'description' => 'Sends notifications for API keys that should be rotated (90+ days old)',
];
