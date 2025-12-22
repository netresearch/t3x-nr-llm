<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'NR LLM - AI Provider Abstraction',
    'description' => 'Unified AI/LLM provider abstraction layer for TYPO3. Supports OpenAI, Claude, Gemini, and other providers with features like chat completion, embeddings, vision, and translation.',
    'category' => 'services',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.99.99',
            'php' => '8.1.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
