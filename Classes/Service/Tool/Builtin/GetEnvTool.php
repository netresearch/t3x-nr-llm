<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Return the process environment variables with secret VALUES redacted.
 *
 * Security contract (see {@see ToolInterface}): every variable whose NAME
 * matches {@see self::SECRET_PATTERN} (passwords, tokens, keys, salts, DSNs,
 * the TYPO3 encryption key, the nr-vault master key, …) has its value replaced
 * by {@see self::REDACTED} before the listing egresses to the provider.
 * Non-secret variables show their value so the model can reason about the host
 * (paths, hostnames, the TYPO3 context) without leaking credentials. The
 * unredacted variant is the separate, default-disabled {@see GetEnvRawTool}.
 */
final readonly class GetEnvTool implements ToolInterface
{
    use CollectsEnvironmentTrait;
    use SafeCastTrait;

    private const SECRET_PATTERN = '/PASS|PASSWORD|PWD|SECRET|TOKEN|KEY|SALT|CREDENTIAL|AUTH|PRIVATE|MASTER|ENCRYPT|DSN|DATABASE_URL|APIKEY|API_KEY/i';

    /**
     * Matches the `user:password@` userinfo of a URL/URI value so credentials
     * embedded in a non-secret-named connection-string variable (a scheme URL
     * carrying a password before the `@`) are redacted while the host/path
     * stays visible for context.
     */
    private const INLINE_CREDENTIAL_PATTERN = '#([a-z][a-z0-9+.\-]*://)[^:@/\s]*:[^@/\s]+@#i';

    private const REDACTED = '***redacted***';

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_env',
            'Return the process environment variables. Values of secret-looking variables '
            . '(password, token, key, secret, salt, DSN, …) are redacted; non-secret values are shown.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $env = $this->collectEnvironment();
        if ($env === []) {
            return 'No environment variables.';
        }

        ksort($env);
        $lines = [];
        foreach ($env as $name => $value) {
            if (preg_match(self::SECRET_PATTERN, $name) === 1) {
                $masked = self::REDACTED;
            } else {
                // Even a non-secret-named variable may carry credentials inside
                // a connection-string value; strip any inline userinfo. Fail
                // closed: a PCRE error (null) redacts the whole value rather
                // than egressing a possibly-credential-bearing string.
                $masked = preg_replace(self::INLINE_CREDENTIAL_PATTERN, '$1' . self::REDACTED . '@', $value) ?? self::REDACTED;
            }
            $lines[] = $name . '=' . $masked;
        }

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes system / host / cross-user data a non-admin must never reach.
        return true;
    }

}
