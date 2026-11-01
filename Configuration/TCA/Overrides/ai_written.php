<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

/*
 * Whether an AI run wrote this record (ADR-239). Set by MarkAiWrittenRecord,
 * read by site templates, cleared by a person who took the text over.
 */
$aiWritten = [
    'tx_nrllm_ai_written' => [
        'exclude'     => true,
        'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_ai_written',
        'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_ai_written.description',
        'config'      => [
            'type'    => 'check',
            'default' => 0,
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('pages', $aiWritten);
ExtensionManagementUtility::addToAllTCAtypes('pages', 'tx_nrllm_ai_written', '', 'after:hidden');

// On tt_content the field `hidden` sits in a palette of that name, which core
// adds to the types after the overrides ran.
ExtensionManagementUtility::addTCAcolumns('tt_content', $aiWritten);
ExtensionManagementUtility::addFieldsToPalette('tt_content', 'hidden', 'tx_nrllm_ai_written', 'after:hidden');
