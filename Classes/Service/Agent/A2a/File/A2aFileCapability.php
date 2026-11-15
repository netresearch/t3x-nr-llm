<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use TYPO3\CMS\Core\Crypto\HashService;

/**
 * The short-lived capability of a file download (spec 051 A2A-FILE5,
 * ADR-284): a token that reads one file of one client without the client's
 * bearer token, for {@see self::LIFETIME_SECONDS}.
 *
 * The token names the file, the client and the expiry, signed together with
 * the hash of the client's current bearer token. Nothing is stored: issuing
 * one changes no row. A token stops working when it expires, when the client
 * gets a new bearer token, and, as the download checks the client too, when
 * the client is disabled. Until then it is a transferable bearer right:
 * whoever holds the URL reads the file.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileCapability
{
    public const LIFETIME_SECONDS = 900;

    private const SECRET = 'nr_llm-a2a-file-capability';

    public function __construct(
        private HashService $hashService,
    ) {}

    /**
     * @return array{token: string, expires: int}
     */
    public function issue(string $fileId, int $clientUid, string $clientTokenHash, int $now): array
    {
        $expires = $now + self::LIFETIME_SECONDS;
        $claims  = rtrim(strtr(base64_encode(json_encode(['f' => $fileId, 'c' => $clientUid, 'e' => $expires], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return ['token' => $claims . '.' . $this->hashService->hmac($claims . '|' . $clientTokenHash, self::SECRET), 'expires' => $expires];
    }

    /**
     * The client a token claims to be issued to, before it is verified; 0
     * when it names none.
     */
    public function claimedClient(string $token): int
    {
        $claims = $this->claims($token);

        return $claims !== null ? $claims['c'] : 0;
    }

    /**
     * Whether a token was issued for this file and the client whose current
     * bearer token has this hash, and has not expired.
     */
    public function grants(string $token, string $fileId, string $clientTokenHash, int $now): bool
    {
        $dot    = strrpos($token, '.');
        $claims = $this->claims($token);
        if ($dot === false || $claims === null || $clientTokenHash === '') {
            return false;
        }

        return $this->hashService->validateHmac(substr($token, 0, $dot) . '|' . $clientTokenHash, self::SECRET, substr($token, $dot + 1))
            && hash_equals($claims['f'], $fileId)
            && $claims['e'] > $now;
    }

    /**
     * @return array{f: string, c: int, e: int}|null
     */
    private function claims(string $token): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr(substr($token, 0, $dot), '-_', '+/'), true), true);
        if (!is_array($decoded) || !is_string($decoded['f'] ?? null) || !is_int($decoded['c'] ?? null) || !is_int($decoded['e'] ?? null)) {
            return null;
        }

        return ['f' => $decoded['f'], 'c' => $decoded['c'], 'e' => $decoded['e']];
    }
}
