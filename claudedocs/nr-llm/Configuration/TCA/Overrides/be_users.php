<?php

declare(strict_types=1);

/**
 * Add LLM permissions to backend users
 */

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Add new fields to be_users
$tempColumns = [
    'tx_nrllm_permissions' => [
        'exclude' => true,
        'label' => 'LLM Permissions',
        'config' => [
            'type' => 'check',
            'items' => [
                ['Use LLM features', 'use_llm'],
                ['Configure prompts', 'configure_prompts'],
                ['Manage API keys', 'manage_keys'],
                ['View reports', 'view_reports'],
                ['Full LLM admin', 'admin_all'],
            ],
            'cols' => 1,
        ],
    ],
    'tx_nrllm_quota_override' => [
        'exclude' => true,
        'label' => 'Override quota limits',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('be_users', $tempColumns);
ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    '--div--;LLM,tx_nrllm_permissions,tx_nrllm_quota_override',
    '',
    'after:TSconfig'
);
