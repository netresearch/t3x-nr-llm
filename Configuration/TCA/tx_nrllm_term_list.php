<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Form\Tca\SiteItems;

/*
 * Forbidden and required terms that stop generated text (ADR-238).
 *
 * The terms are text fields, one term per line, for the reasons ADR-208 gives
 * for the glossary. A list must name a configuration: that is the whole scope
 * question, and a list naming none would look like protection and be none.
 */
return [
    'ctrl' => [
        'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list',
        'label'          => 'name',
        'tstamp'         => 'tstamp',
        'crdate'         => 'crdate',
        'delete'         => 'deleted',
        'default_sortby' => 'name ASC, uid ASC',
        'enablecolumns'  => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => ['default' => 'nrllm-record-snippet'],
        'rootLevel'        => -1,
        'security'         => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    name,
                    --palette--;;scope,
                    forbidden_terms,
                    required_terms,
                    message,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
    'palettes' => [
        'scope' => [
            'label'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:palette.term_list_scope',
            'showitem' => 'configurations, --linebreak--, site_identifier, language',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label'  => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type'    => 'check',
                'default' => 0,
            ],
        ],
        'name' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.name',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.name.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 50,
                'max'      => 255,
                'trim'     => true,
                'required' => true,
            ],
        ],
        'configurations' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.configurations',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.configurations.description',
            'config'      => [
                'type'                => 'select',
                'renderType'          => 'selectMultipleSideBySide',
                'foreign_table'       => 'tx_nrllm_configuration',
                'foreign_table_where' => 'ORDER BY tx_nrllm_configuration.name',
                'size'                => 6,
                'minitems'            => 1,
                'maxitems'            => 999,
            ],
        ],
        'site_identifier' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.site_identifier',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.site_identifier.description',
            'config'      => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:termList.allSites', 'value' => ''],
                ],
                'itemsProcFunc' => SiteItems::class . '->addItems',
            ],
        ],
        'language' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.language',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.language.description',
            'config'      => [
                'type'        => 'input',
                'size'        => 5,
                'max'         => 2,
                'trim'        => true,
                'eval'        => 'alpha,lower',
                'placeholder' => 'de',
                'searchable'  => false,
            ],
        ],
        'forbidden_terms' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.forbidden_terms',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.forbidden_terms.description',
            'config'      => [
                'type'        => 'text',
                'cols'        => 60,
                'rows'        => 10,
                'fixedFont'   => true,
                'placeholder' => "Billigprodukt\nRabatt*",
            ],
        ],
        'required_terms' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.required_terms',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.required_terms.description',
            'config'      => [
                'type'      => 'text',
                'cols'      => 60,
                'rows'      => 5,
                'fixedFont' => true,
            ],
        ],
        'message' => [
            'label'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.message',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_term_list.message.description',
            'config'      => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 3,
                'max'  => 500,
                'trim' => true,
            ],
        ],
    ],
];
