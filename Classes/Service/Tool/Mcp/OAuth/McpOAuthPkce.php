<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * PKCE (RFC 7636) with the S256 method, and the `state` value (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthPkce
{
    /**
     * 32 random bytes, base64url without padding: 43 characters, the minimum
     * length RFC 7636 section 4.1 allows and 256 bits of entropy.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * `BASE64URL-ENCODE(SHA256(ASCII(code_verifier)))`, RFC 7636 section 4.2.
     */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function state(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
