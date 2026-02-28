<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'NR LLM - AI Provider Abstraction',
    'description' => 'Unified AI/LLM provider abstraction layer for TYPO3. Supports OpenAI, Claude, Gemini, and other providers with features like chat completion, embeddings, vision, and translation. - by Netresearch',
    'category' => 'services',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.2.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
