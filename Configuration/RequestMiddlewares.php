<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Middleware\McpOAuthCallbackBounce;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerMiddleware;

return [
    'frontend' => [
        // Spec 051 A2A-SV1: the A2A endpoint and agent card below a site's
        // configured base URL. Before the site is resolved, so an A2A request
        // never reaches page resolution. TYPO3's maintenance mode runs after
        // the site and therefore does not close it; a2a.server.enabled does.
        'netresearch/nr-llm/a2a-server' => [
            'target' => A2aServerMiddleware::class,
            'after'  => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
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
