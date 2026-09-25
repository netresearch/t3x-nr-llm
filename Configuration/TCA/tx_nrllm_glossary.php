<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Form\Tca\SiteItems;

/*
 * Translation glossary per site and language pair (ADR-208).
 *
 * The term pairs are one text field, one pair per line, rather than an inline
 * child table: see ADR-208 for why. The two DeepL bookkeeping columns
 * (`deepl_glossary_id`, `deepl_entries_hash`) are deliberately NOT declared
 * here — they are written by the translation path, and a column the form does
 * not know is one no editor can set and no record copy carries over as data
 * the editor meant.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary',
        'label' => 'name',
        'label_alt' => 'site_identifier,source_language,target_language',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // No manual sorting: which of two records for the same site and pair
        // applies is decided by uid (ADR-208), and a drag order in the list
        // module would suggest otherwise.
        'default_sortby' => 'site_identifier ASC, source_language ASC, target_language ASC, uid ASC',
        'searchFields' => 'name,site_identifier,entries',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:nr_llm/Resources/Public/Icons/Snippet.svg',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    name,
                    --palette--;;scope,
                    entries,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
    'palettes' => [
        'scope' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.glossary_scope',
            'showitem' => 'site_identifier, --linebreak--, source_language, target_language',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.name',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.name.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'trim' => true,
                'required' => true,
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.site_identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.site_identifier.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                ],
                'itemsProcFunc' => SiteItems::class . '->addItems',
                'required' => true,
            ],
        ],
        'source_language' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.source_language',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.source_language.description',
            'config' => [
                'type' => 'input',
                'size' => 5,
                'max' => 2,
                'min' => 2,
                'trim' => true,
                'eval' => 'alpha,lower',
                'required' => true,
                'placeholder' => 'de',
            ],
        ],
        'target_language' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.target_language',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.target_language.description',
            'config' => [
                'type' => 'input',
                'size' => 5,
                'max' => 2,
                'min' => 2,
                'trim' => true,
                'eval' => 'alpha,lower',
                'required' => true,
                'placeholder' => 'en',
            ],
        ],
        'entries' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.entries',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_glossary.entries.description',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 15,
                'required' => true,
                'fixedFont' => true,
                'enableTabulator' => true,
                'placeholder' => "Warenkorb = shopping cart\nKundenkonto = customer account",
            ],
        ],
    ],
];
