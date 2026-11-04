<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Middleware\McpOAuthCallbackBounce;

return [
    'backend' => [
        // ADR-259: before authentication, because the request it handles is
        // the one that arrives without the backend session cookie; after
        // routing, because it acts on one route only.
        'netresearch/nr-llm/mcp-oauth-callback-bounce' => [
            'target' => McpOAuthCallbackBounce::class,
            'after'  => [
                'typo3/cms-backend/backend-routing',
            ],
            'before' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
