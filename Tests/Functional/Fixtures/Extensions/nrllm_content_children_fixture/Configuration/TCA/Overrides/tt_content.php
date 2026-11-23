<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

defined('TYPO3') || exit;

/*
 * Content types for ADR-324, one per rule:
 *
 * - nrllm_items: an inline column of items and a FlexForm with defaults —
 *   offered, created with its children and the defaults;
 * - nrllm_slides: an inline column whose child table has an authMode type
 *   field — offered;
 * - nrllm_folder: a folder reference — offered, left empty;
 * - nrllm_mm: an inline column with an MM table — excluded;
 * - nrllm_reqflex: a FlexForm with a required field without default —
 *   excluded;
 * - nrllm_reqgroup: a required group reference — excluded.
 */
/** @var array{tt_content: array{columns: array<string, mixed>, types: array<string, mixed>}} $tca */
$tca = &$GLOBALS['TCA'];

$tca['tt_content']['columns'] += [
    'tx_childrenfixture_items' => [
        'label'  => 'Items',
        'config' => [
            'type'          => 'inline',
            'foreign_table' => 'tx_childrenfixture_item',
            'foreign_field' => 'parent_content',
            'maxitems'      => 3,
        ],
    ],
    'tx_childrenfixture_slides' => [
        'label'  => 'Slides',
        'config' => [
            'type'          => 'inline',
            'foreign_table' => 'tx_childrenfixture_slide',
            'foreign_field' => 'parent_content',
        ],
    ],
    'tx_childrenfixture_mm' => [
        'label'  => 'Linked items',
        'config' => [
            'type'          => 'inline',
            'foreign_table' => 'tx_childrenfixture_item',
            'MM'            => 'tx_childrenfixture_item_mm',
        ],
    ],
    'tx_childrenfixture_folder' => [
        'label'  => 'Folder',
        'config' => ['type' => 'folder'],
    ],
    'tx_childrenfixture_required' => [
        'label'  => 'Required records',
        'config' => ['type' => 'group', 'allowed' => 'pages', 'minitems' => 1],
    ],
];

// Written into the item list directly: addTcaSelectItem() is deprecated in
// 14 and its replacement addRecordType() does not exist in 13.4.
/** @var array{config: array{items: list<array<string, string>>}} $cType */
$cType = &$tca['tt_content']['columns']['CType'];
foreach (['nrllm_items', 'nrllm_slides', 'nrllm_folder', 'nrllm_mm', 'nrllm_reqflex', 'nrllm_reqgroup'] as $type) {
    $cType['config']['items'][] = [
        'label'       => $type,
        'value'       => $type,
        'description' => 'Fixture type ' . $type,
    ];
}

unset($cType);

// The data structure per type: 14.3 reads it from the type's
// columnsOverrides, 13.4 from the column's ds map keyed by pointer value.
$isV14          = (new Typo3Version())->getMajorVersion() >= 14;
$dataStructures = [
    'nrllm_items'   => 'FILE:EXT:nrllm_content_children_fixture/Configuration/FlexForms/Items.xml',
    'nrllm_reqflex' => 'FILE:EXT:nrllm_content_children_fixture/Configuration/FlexForms/RequiredWithoutDefault.xml',
];
$withDataStructure = static fn (string $type, string $showitem): array => $isV14 && isset($dataStructures[$type])
    ? ['showitem' => $showitem, 'columnsOverrides' => ['pi_flexform' => ['config' => ['ds' => $dataStructures[$type]]]]]
    : ['showitem' => $showitem];

$tca['tt_content']['types']['nrllm_items']    = $withDataStructure('nrllm_items', '--palette--;;headers, tx_childrenfixture_items, pi_flexform');
$tca['tt_content']['types']['nrllm_slides']   = ['showitem' => '--palette--;;headers, tx_childrenfixture_slides'];
$tca['tt_content']['types']['nrllm_folder']   = ['showitem' => '--palette--;;headers, bodytext, tx_childrenfixture_folder'];
$tca['tt_content']['types']['nrllm_mm']       = ['showitem' => '--palette--;;headers, tx_childrenfixture_mm'];
$tca['tt_content']['types']['nrllm_reqflex']  = $withDataStructure('nrllm_reqflex', '--palette--;;headers, pi_flexform');
$tca['tt_content']['types']['nrllm_reqgroup'] = ['showitem' => '--palette--;;headers, tx_childrenfixture_required'];

$piFlexform = $tca['tt_content']['columns']['pi_flexform'] ?? null;
if (!$isV14 && is_array($piFlexform) && is_array($piFlexform['config'] ?? null)) {
    $config = $piFlexform['config'];
    $map    = is_array($config['ds'] ?? null) ? $config['ds'] : [];
    foreach ($dataStructures as $type => $dataStructure) {
        $map['*,' . $type] = $dataStructure;
    }

    $config['ds']                                = $map;
    $piFlexform['config']                        = $config;
    $tca['tt_content']['columns']['pi_flexform'] = $piFlexform;
}

unset($tca);
