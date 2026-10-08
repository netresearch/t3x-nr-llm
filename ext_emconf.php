<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'LLM Foundation',
    'description' => 'Shared AI foundation: configure LLM providers once for every AI extension, with encrypted API keys and services for chat, translation, vision and embeddings.',
    'category' => 'services',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.40.0',
    'constraints' => [
        // composer.json is the authoritative dependency constraint
        // ("typo3/cms-core": "^13.4 || ^14.3"). The TER `depends` format only
        // supports a single contiguous min-max range and therefore cannot
        // express the 14.0–14.2 gap; the broad range below is intentional and
        // never under-claims the supported 13.4 / 14.3 targets.
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
