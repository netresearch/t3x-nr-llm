<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * Scope strings as RFC 6749 section 3.3 defines them (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthScope
{
    /**
     * Space-separated, each token once, in first-seen order. A token with a
     * character RFC 6749 does not allow (`"`, `\`, controls, non-ASCII) is
     * dropped rather than sent: the value goes into a URL and a form.
     */
    public static function normalize(string $scope): string
    {
        $tokens = [];
        foreach (preg_split('/ +/', trim($scope)) ?: [] as $token) {
            if ($token !== '' && preg_match('/\A[\x21\x23-\x5B\x5D-\x7E]+\z/D', $token) === 1) {
                $tokens[$token] = true;
            }
        }

        return implode(' ', array_keys($tokens));
    }

    /**
     * The scope to request: what the operator configured, otherwise what the
     * server suggested. `offline_access` is added when the authorization server
     * lists it (MCP "Refresh Tokens": a client MAY, to receive a refresh token).
     *
     * @param list<string> $authorizationServerScopes
     */
    public static function select(string $configured, string $suggested, array $authorizationServerScopes): string
    {
        $scope = self::normalize($configured) !== '' ? self::normalize($configured) : self::normalize($suggested);
        if (in_array('offline_access', $authorizationServerScopes, true)) {
            $scope = self::normalize($scope . ' offline_access');
        }

        return $scope;
    }
}
