<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aDomainHandlerItems;

$ll = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_a2a_profile';

/*
 * A publication profile of the A2A server role (spec 051 A2A-SV4, ADR-283):
 * one agent skill, bound to a domain handler, an LLM configuration and the
 * read-only tools its runs may use. Only administrators edit it. A profile
 * whose configuration cannot keep a result is not saved as public
 * (ADR-282), and a writing profile is stage 4.
 */
return [
    'ctrl' => [
        'title'            => $ll,
        'label'            => 'name',
        'label_alt'        => 'skill_id',
        'label_alt_force'  => true,
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'delete'           => 'deleted',
        'enablecolumns'    => ['disabled' => 'hidden'],
        'default_sortby'   => 'skill_id ASC',
        'typeicon_classes' => ['default' => 'nrllm-record-a2a-agent'],
        'rootLevel'        => -1,
        'adminOnly'        => true,
        'security'         => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    skill_id,
                    name,
                    description,
                    tags,
                    examples,
                --div--;' . $ll . '.tab.execution,
                    handler,
                    configuration_uid,
                    tools,
                    input_modes,
                    output_modes,
                    max_active_tasks,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    public,
                    hidden,
',
        ],
    ],
    'columns' => [
        'skill_id' => [
            'label'       => $ll . '.skill_id',
            'description' => $ll . '.skill_id.description',
            'config'      => ['type' => 'input', 'size' => 30, 'max' => 64, 'eval' => 'alphanum_x,lower,unique', 'trim' => true, 'required' => true],
        ],
        'name' => [
            'label'  => $ll . '.name',
            'config' => ['type' => 'input', 'size' => 50, 'max' => 255, 'trim' => true, 'required' => true],
        ],
        'description' => [
            'label'  => $ll . '.description',
            'config' => ['type' => 'text', 'cols' => 40, 'rows' => 3, 'required' => true],
        ],
        'tags' => [
            'label'       => $ll . '.tags',
            'description' => $ll . '.tags.description',
            'config'      => ['type' => 'input', 'size' => 50, 'max' => 1024, 'trim' => true, 'required' => true],
        ],
        'examples' => [
            'label'       => $ll . '.examples',
            'description' => $ll . '.examples.description',
            'config'      => ['type' => 'text', 'cols' => 40, 'rows' => 3, 'searchable' => false],
        ],
        'handler' => [
            'label'       => $ll . '.handler',
            'description' => $ll . '.handler.description',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'items'         => [['label' => '', 'value' => '']],
                'itemsProcFunc' => A2aDomainHandlerItems::class . '->addItems',
                'required'      => true,
            ],
        ],
        'configuration_uid' => [
            'label'       => $ll . '.configuration_uid',
            'description' => $ll . '.configuration_uid.description',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'foreign_table' => 'tx_nrllm_configuration',
                'items'         => [['label' => '', 'value' => 0]],
                'default'       => 0,
                'required'      => true,
            ],
        ],
        'tools' => [
            'label'       => $ll . '.tools',
            'description' => $ll . '.tools.description',
            'config'      => ['type' => 'text', 'cols' => 40, 'rows' => 2, 'searchable' => false],
        ],
        'input_modes' => [
            'label'  => $ll . '.input_modes',
            'config' => ['type' => 'input', 'size' => 30, 'default' => 'text/plain', 'readOnly' => true, 'searchable' => false],
        ],
        'output_modes' => [
            'label'  => $ll . '.output_modes',
            'config' => ['type' => 'input', 'size' => 30, 'default' => 'text/plain', 'readOnly' => true, 'searchable' => false],
        ],
        'max_active_tasks' => [
            'label'       => $ll . '.max_active_tasks',
            'description' => $ll . '.max_active_tasks.description',
            'config'      => ['type' => 'number', 'default' => 0, 'range' => ['lower' => 0, 'upper' => 10000]],
        ],
        'public' => [
            'label'       => $ll . '.public',
            'description' => $ll . '.public.description',
            'config'      => ['type' => 'check', 'default' => 0],
        ],
        'hidden' => [
            'label'  => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.disable',
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle', 'default' => 0],
        ],
    ],
];
