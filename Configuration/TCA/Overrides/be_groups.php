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

/*
 * The limits of the A2A tokens an editor of this group may issue for their own
 * account (ADR-294). They qualify the grant `a2a_token_self_service` and mean
 * nothing without it: a group that sets a relaxation but does not hold the
 * grant contributes nothing. be_groups is adminOnly, so an editor cannot
 * relax his own limits. Every field defaults to the strict value.
 */
$ll = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:be_groups.';
ExtensionManagementUtility::addTCAcolumns('be_groups', [
    'tx_nrllm_a2a_allow_live' => [
        'label'       => $ll . 'tx_nrllm_a2a_allow_live',
        'description' => $ll . 'tx_nrllm_a2a_allow_live.description',
        'config'      => ['type' => 'check', 'default' => 0],
    ],
    'tx_nrllm_a2a_max_expiry_days' => [
        'label'       => $ll . 'tx_nrllm_a2a_max_expiry_days',
        'description' => $ll . 'tx_nrllm_a2a_max_expiry_days.description',
        'config'      => ['type' => 'number', 'default' => 0, 'range' => ['lower' => 0, 'upper' => 730]],
    ],
    'tx_nrllm_a2a_allow_no_expiry' => [
        'label'       => $ll . 'tx_nrllm_a2a_allow_no_expiry',
        'description' => $ll . 'tx_nrllm_a2a_allow_no_expiry.description',
        'config'      => ['type' => 'check', 'default' => 0],
    ],
    'tx_nrllm_a2a_profiles' => [
        'label'       => $ll . 'tx_nrllm_a2a_profiles',
        'description' => $ll . 'tx_nrllm_a2a_profiles.description',
        'config'      => [
            'type'          => 'select',
            'renderType'    => 'selectMultipleSideBySide',
            'foreign_table' => 'tx_nrllm_a2a_profile',
            'size'          => 6,
            'autoSizeMax'   => 20,
            'maxitems'      => 100,
        ],
    ],
]);
ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    '--div--;' . $ll . 'tab.a2a, tx_nrllm_a2a_allow_live, tx_nrllm_a2a_max_expiry_days, tx_nrllm_a2a_allow_no_expiry, tx_nrllm_a2a_profiles',
);
