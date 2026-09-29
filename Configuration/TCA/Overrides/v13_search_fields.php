<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

defined('TYPO3') || die();

// TYPO3 v13 reads the backend search scope from `ctrl.searchFields` and ignores
// the per-column `searchable` flag the base TCA files set. TYPO3 v14 removed
// `searchFields` (#106972) and logs a deprecation for every table that still
// sets it, so it is set here on v13 only. Remove this file with v13 support.
if ((new Typo3Version())->getMajorVersion() < 14) {
    $searchFields = [
        'tx_nrllm_configuration' => 'identifier,name,description',
        'tx_nrllm_glossary'      => 'name,site_identifier,entries',
        'tx_nrllm_mcp_server'    => 'identifier,name,description,url',
        'tx_nrllm_model'         => 'identifier,name,description,model_id',
        'tx_nrllm_promptsnippet' => 'identifier,name,description,tags,snippet',
        'tx_nrllm_provider'      => 'identifier,name,description,adapter_type',
        'tx_nrllm_skill'         => 'identifier,name,description',
        'tx_nrllm_skill_source'  => 'title,url,ref',
        'tx_nrllm_task'          => 'identifier,name,description,category',
    ];

    $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
    foreach ($searchFields as $table => $fields) {
        if (is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null)) {
            $tca[$table]['ctrl']['searchFields'] = $fields;
        }
    }

    $GLOBALS['TCA'] = $tca;

    unset($searchFields, $tca, $table, $fields);
}
