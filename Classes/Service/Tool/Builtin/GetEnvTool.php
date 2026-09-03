<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * Return the process environment variables with secret VALUES redacted.
 *
 * Security contract (see {@see ToolInterface}): a variable's value is withheld
 * when EITHER test says secret, because each catches what the other misses:
 *
 * - its NAME matches {@see self::SECRET_PATTERN} (passwords, tokens, keys,
 *   salts, DSNs, the TYPO3 encryption key, the nr-vault master key, …);
 * - or its VALUE carries a recognised secret shape
 *   ({@see SecretShapeRedactorTrait}).
 *
 * The value test is not redundant. Matching on names alone leaked any variable
 * whose name gives nothing away: `GITHUB_PAT=ghp_…` and `STRIPE_LIVE=sk_live_…`
 * both egressed verbatim to the provider, because neither name contains
 * "TOKEN", "KEY" or "SECRET" (ADR-123).
 *
 * Non-secret variables show their value so the model can reason about the host
 * (paths, hostnames, the TYPO3 context) without leaking credentials. The
 * unredacted variant is the separate, default-disabled {@see GetEnvRawTool}.
 */
final readonly class GetEnvTool implements ToolInterface
{
    use CollectsEnvironmentTrait;
    use SafeCastTrait;
    use SecretShapeRedactorTrait;

    private const SECRET_PATTERN = '/PASS|PASSWORD|PWD|SECRET|TOKEN|KEY|SALT|CREDENTIAL|AUTH|PRIVATE|MASTER|ENCRYPT|DSN|DATABASE_URL|APIKEY|API_KEY/i';

    /**
     * Matches the whole `user:password@` userinfo of a URL/URI value so
     * credentials embedded in a non-secret-named connection-string variable are
     * redacted while the host/path stays visible for context.
     *
     * Deliberately stricter than the shared trait's equivalent, which masks only
     * the password and keeps the username: in an error message a username is
     * useful context, but in a tool listing that egresses to a third party it is
     * half a credential. Kept here rather than tightened in the shared trait,
     * which would silently change what provider error messages disclose.
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

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $env = $this->collectEnvironment();
        if ($env === []) {
            return ToolResult::text('No environment variables.');
        }

        ksort($env);
        $lines = [];
        foreach ($env as $name => $value) {
            $lines[] = $name . '=' . $this->maskValue($name, $value);
        }

        return ToolResult::text(implode("\n", $lines));
    }

    /**
     * Withhold the whole value when its NAME says secret; otherwise mask any
     * secret SHAPE inside it.
     *
     * Fails CLOSED, unlike the guardrails: this listing egresses to a third-party
     * provider, so a value the redactor could not fully inspect is withheld
     * entirely rather than forwarded. Losing one line of host context is cheaper
     * than leaking a credential.
     */
    private function maskValue(string|int $name, string $value): string
    {
        // int is not a defensive `mixed`: PHP casts a numeric-string array key
        // back to int, so `collectEnvironment()` writing $env[self::toStr($name)]
        // still yields int 5 for an environment variable named `5`. Under
        // strict_types a `string` parameter rejects that AT THE CALL, before any
        // cast in this body could run -- which is why the annotation the caller
        // used to carry silenced the analyser and left the TypeError standing.
        $nameStr = (string)$name;
        if (preg_match(self::SECRET_PATTERN, $nameStr) === 1) {
            return self::REDACTED;
        }

        // Whole userinfo first, so the username is gone before the shared pass
        // (which would keep it) ever sees the value.
        $masked = preg_replace(self::INLINE_CREDENTIAL_PATTERN, '$1' . self::REDACTED . '@', $value);
        if (!is_string($masked)) {
            return self::REDACTED;
        }

        // Then every recognised secret shape, masked in place: a connection-string
        // variable still shows its host and path, which is the context this tool
        // exists to provide, while a standalone secret leaves nothing but the mask.
        return $this->redactSecretShapesStrict($masked) ?? self::REDACTED;
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

    public function getGroup(): string
    {
        return 'system';
    }
}
