<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\McpOAuthCallbackController;

return [
    // ADR-259: the redirect URI of the OAuth connection of an MCP server.
    // `access: public` drops the request token only, which an authorization
    // server cannot know; the backend still requires the session, and the
    // controller an administrator and a matching `state`.
    'nrllm_mcp_oauth_callback' => [
        'path'   => '/nrllm/mcp/oauth/callback',
        'access' => 'public',
        'target' => McpOAuthCallbackController::class . '::handleAction',
    ],
];
