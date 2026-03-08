<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'LLM — Shared AI Foundation for TYPO3',
    'description' => 'Shared AI foundation for TYPO3. Configure LLM providers once — every AI extension uses them. Supports OpenAI, Anthropic, Google Gemini, Ollama, and more. Includes services for chat, translation, vision, and embeddings with encrypted API keys and full admin control.',
    'category' => 'services',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.4.11',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
