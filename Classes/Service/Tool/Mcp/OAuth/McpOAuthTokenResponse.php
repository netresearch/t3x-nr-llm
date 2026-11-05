<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use SensitiveParameter;

/**
 * A validated token response (ADR-259).
 *
 * It holds token values in this process for exactly as long as it takes to
 * write them into Vault; {@see self::forget()} drops them afterwards.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthTokenResponse
{
    private const MAX_EXPIRES_AT = 2147483647;

    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public string $refreshToken,
        // Seconds; 0 when the response named no lifetime.
        public readonly int $expiresIn,
        // '' when the response named no scope (RFC 6749 section 5.1: then it
        // is the scope that was requested).
        public readonly string $scope,
    ) {}

    /**
     * The Unix time the token expires when issued at `$issuedAt`; 0 when the
     * response named no lifetime. Capped at the largest value the signed
     * 32-bit `expires_at` column holds, which a ten-year lifetime passes
     * from 2028 on.
     */
    public function expiresAt(int $issuedAt): int
    {
        return $this->expiresIn > 0 ? min($issuedAt + $this->expiresIn, self::MAX_EXPIRES_AT) : 0;
    }

    /**
     * Drop the values once Vault holds them. `sodium_memzero()` is not used
     * because it sets its argument to null, which a typed property refuses.
     */
    public function forget(): void
    {
        $this->accessToken  = '';
        $this->refreshToken = '';
    }
}
