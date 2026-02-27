<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * ES6 JavaScript module import map configuration.
 *
 * Maps module specifiers to actual file paths for TYPO3 v12+ import maps.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/JavaScript/ES6/Index.html
 */
return [
    'dependencies' => [
        'backend',
    ],
    'imports' => [
        // Main namespace for all nr_llm JavaScript modules
        '@netresearch/nr-llm/' => 'EXT:nr_llm/Resources/Public/JavaScript/',
    ],
];
