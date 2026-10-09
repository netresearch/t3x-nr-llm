<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$syncedShowitem = '
    --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
        source,
        name,
        identifier,
        description,
        body,
    --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.metadata,
        trust_level,
        data_class,
        injection_scan,
        support_status,
        unsupported_notes,
        allowed_tools,
        process,
        source_sha,
        body_checksum,
        version_digest,
        raw_frontmatter,
    --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
        enabled,
        orphaned,
        hidden,
';

$syncedOverrides = [
    'source' => ['config' => ['readOnly' => true]],
];

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill',
        'type' => 'source:type',
        'label' => 'name',
        'label_alt' => 'identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'name ASC',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => ['default' => 'nrllm-record-skill'],
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    // The form follows the type of the skill's source (ADR-214 item 3). A
    // synced skill is written by the sync only, so every field the version
    // digest covers is read-only. A backend-authored skill is written here.
    // The source is not moved in the form: a synced skill keeps its source,
    // and a backend skill offers only backend sources. A convenience — the
    // control is that a record carrying sync-written values stays checked
    // against them on any source (SkillComposer::isEditedInPlace()).
    'types' => [
        '0' => ['showitem' => $syncedShowitem, 'columnsOverrides' => $syncedOverrides],
        'single_file' => ['showitem' => $syncedShowitem, 'columnsOverrides' => $syncedOverrides],
        'repo' => ['showitem' => $syncedShowitem, 'columnsOverrides' => $syncedOverrides],
        'marketplace' => ['showitem' => $syncedShowitem, 'columnsOverrides' => $syncedOverrides],
        'backend' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    source,
                    name,
                    identifier,
                    description,
                    body,
                --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tab.metadata,
                    allowed_tools,
                    process,
                    data_class,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    enabled,
                    hidden,
            ',
            'columnsOverrides' => [
                'source' => ['config' => ['foreign_table_where' => "AND {#tx_nrllm_skill_source}.{#type} = 'backend' ORDER BY tx_nrllm_skill_source.title"]],
                'identifier' => ['config' => ['readOnly' => false, 'required' => true]],
                'name' => ['config' => ['readOnly' => false]],
                'description' => ['config' => ['readOnly' => false]],
                'body' => ['config' => ['readOnly' => false]],
                'allowed_tools' => [
                    'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.allowed_tools.backend.description',
                    'config' => ['readOnly' => false],
                ],
                'process' => ['config' => ['readOnly' => false]],
            ],
        ],
    ],
    'columns' => [
        // The fields marked exclude (ADR-214 item 3) decide what a skill may
        // say, which tools it grants, whether its approval still holds and
        // whether it is active.
        // A group granted tables_modify on skills reaches them only through
        // an explicit exclude-field grant.
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        // Excluded (ADR-214 item 3): moving a synced skill onto the backend
        // source would switch off its stored-value integrity check. The
        // approval's source binding covers an administrator doing it.
        'source' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.source',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_nrllm_skill_source',
                'foreign_table_where' => 'ORDER BY tx_nrllm_skill_source.title',
                'minitems' => 1,
                'maxitems' => 1,
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.identifier',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 512,
                'readOnly' => true,
            ],
        ],
        // name, description and body are part of the version digest (ADR-214
        // item 1) and written by the sync: an edit here fails the compose-time
        // integrity check, so FormEngine shows them read-only.
        'name' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'trim' => true,
                'required' => true,
                'readOnly' => true,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'body' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.body',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 12,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'body_checksum' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.body_checksum',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        // Sync-managed (ADR-214 item 1): the digest over body and frontmatter
        // fields that approvals, revocations and pins name.
        'version_digest' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.version_digest',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.version_digest.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 80,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        // Sync-managed (ADR-214 item 6): the frontmatter's process marker.
        'process' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.process',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.process.description',
            'config' => [
                'type' => 'check',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'source_sha' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.source_sha',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'raw_frontmatter' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.raw_frontmatter',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        // Denormalized from the source by the sync.
        // A SECOND axis from trust_level (ADR-144): trust says who wrote the
        // skill, this says how sensitive what it carries is. A first-party
        // skill can still hold confidential material. Empty means undeclared
        // and cannot block, so ingested skills keep working untouched.
        // Excluded: clearing it lifts the egress ceiling for the skill's text,
        // and no approval covers it (ADR-214 item 3).
        'data_class' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.data_class',
            'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.data_class.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.publicContent', 'value' => 'publicContent'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.editorContent', 'value' => 'editorContent'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.sourceCode', 'value' => 'sourceCode'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.internalConfiguration', 'value' => 'internalConfiguration'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.systemDiagnostics', 'value' => 'systemDiagnostics'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.tool_data_class.secretAdjacent', 'value' => 'secretAdjacent'],
                ],
            ],
        ],
        'trust_level' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.trust_level',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.trust_level.untrusted', 'value' => 'untrusted'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.trust_level.community', 'value' => 'community'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.trust_level.verified', 'value' => 'verified'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm.trust_level.first_party', 'value' => 'first_party'],
                ],
                'default' => 'untrusted',
                'readOnly' => true,
            ],
        ],
        'injection_scan' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.injection_scan',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 4,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'support_status' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.support_status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.support_status.full', 'value' => 'full'],
                    ['label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.support_status.partial', 'value' => 'partial'],
                ],
                'default' => 'full',
                'readOnly' => true,
            ],
        ],
        'unsupported_notes' => [
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.unsupported_notes',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'allowed_tools' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.allowed_tools',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'orphaned' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.orphaned',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'enabled' => [
            'exclude' => true,
            'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.enabled',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
];
