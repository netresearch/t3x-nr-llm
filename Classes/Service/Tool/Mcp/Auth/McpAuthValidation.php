<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * @internal Credential-free boundary validation for ADR-217.
 */
final class McpAuthValidation
{
    public static function profileIdentifier(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid delegation profile identifier.', 5347809945);
        }
    }

    /**
     * Canonical Vault UUIDv7 or an ASCII alias of 3 to 255 characters.
     */
    public static function credentialIdentifier(string $value): void
    {
        $isUuidV7 = strlen($value) === 36 && Uuid::isValid($value) && $value[14] === '7';
        $isAlias = preg_match('/\A[A-Za-z][A-Za-z0-9_]{2,254}\z/D', $value) === 1;
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || !$isUuidV7 && !$isAlias) {
            throw new InvalidArgumentException(
                'A delegation credential must be a canonical Vault identifier.',
                6331797530,
            );
        }
    }

    public static function endpoint(string $value): void
    {
        $parts = parse_url($value);
        if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value) === 1 || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Delegation requires an HTTPS endpoint without userinfo or fragment.', 6884498067);
        }
    }

    public static function clientId(string $value): void
    {
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid delegation client identifier.', 8587521694);
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function audiences(array $values): array
    {
        self::listBounds($values, 32);
        foreach ($values as $value) {
            if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Invalid delegation audience.', 2213971144);
            }
        }

        return self::canonical($values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function scopes(array $values): array
    {
        self::listBounds($values, 64);
        foreach ($values as $value) {
            if (preg_match('/\A[\x21\x23-\x5B\x5D-\x7E]{1,128}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid delegation scope.', 9285423102);
            }
        }

        return self::canonical($values);
    }

    /**
     * @return list<string>
     */
    public static function scopeString(string $value): array
    {
        if (strlen($value) > 8255 || $value === '' || trim($value) !== $value || str_contains($value, '  ')) {
            throw new InvalidArgumentException('Invalid delegation scope set.', 7590336107);
        }

        return self::scopes(explode(' ', $value));
    }

    /**
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedScopes
     * @param list<string> $requestedScopes
     */
    public static function grant(
        string $audience,
        array $requestedScopes,
        array $allowedAudiences,
        array $allowedScopes,
    ): void {
        if (!in_array($audience, $allowedAudiences, true) || array_diff($requestedScopes, $allowedScopes) !== []) {
            throw new InvalidArgumentException('Delegation exceeds the configured grant.', 1997527714);
        }
    }

    /**
     * @param list<string> $values
     */
    private static function listBounds(array $values, int $maximum): void
    {
        if ($values === [] || count($values) > $maximum) {
            throw new InvalidArgumentException('Invalid delegation permission-list bounds.', 1716704270);
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Delegation permissions must be strings.', 5441478469);
            }
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function canonical(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);
        return $values;
    }
}
