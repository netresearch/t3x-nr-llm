<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

/*
 * The backend groups whose members' AI usage and cost the holders of the
 * usage_view_groups grant in this group may see (ADR-277). Shaped like core's
 * `subgroup` field: a comma list of be_groups uids. be_groups is adminOnly,
 * so only an administrator sets it — a holder cannot widen his own view.
 * Empty by default: the feature shows nobody until an administrator assigns
 * groups AND grants the permission.
 */
ExtensionManagementUtility::addTCAcolumns('be_groups', [
    'tx_nrllm_usage_visible_groups' => [
        'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:be_groups.tx_nrllm_usage_visible_groups',
        'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:be_groups.tx_nrllm_usage_visible_groups.description',
        'config'      => [
            'type'                => 'select',
            'renderType'          => 'selectMultipleSideBySide',
            'foreign_table'       => 'be_groups',
            'foreign_table_where' => 'ORDER BY be_groups.title',
            'size'                => 5,
            'autoSizeMax'         => 20,
        ],
    ],
]);
ExtensionManagementUtility::addToAllTCAtypes('be_groups', 'tx_nrllm_usage_visible_groups', '', 'after:custom_options');
