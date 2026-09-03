<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetEnvTool.
 *
 * Load-bearing: a variable whose NAME matches a secret pattern has its VALUE
 * redacted before egress, while a non-secret variable keeps its value. Real
 * process env vars are set with putenv() and cleaned up afterwards.
 */
#[CoversClass(GetEnvTool::class)]
final class GetEnvToolTest extends TestCase
{
    private const PLAIN_KEY = 'NRLLM_TEST_PLAIN_HOST';

    private const PLAIN_VALUE = 'web-01.example.test';

    private const SECRET_KEY = 'NRLLM_TEST_DB_PASSWORD';

    private const SECRET_VALUE = 'sup3r-s3cr3t-value';

    // Name uses PWD (not PASS), matched by the secret-name pattern.
    private const PWD_KEY = 'NRLLM_TEST_MYSQL_PWD';

    private const PWD_VALUE = 'dbpass123';

    // Non-secret NAME whose VALUE embeds credentials in a connection URL.
    private const URL_KEY = 'NRLLM_TEST_REDIS_URL';

    private const URL_VALUE = 'redis://cacheuser:s3cr3turl@cache-01:6379/0';

    // Same, but with an EMPTY username (redis://:password@host).
    private const NOUSER_URL_KEY = 'NRLLM_TEST_NOUSER_URL';

    private const NOUSER_URL_VALUE = 'redis://:s3cr3tnouser@cache-02:6379/0';

    // Names that give NOTHING away — no PASS/KEY/SECRET/TOKEN substring — whose
    // values are unmistakable secrets. Matching on names alone egressed both
    // verbatim to the provider (ADR-123).
    private const SHAPED_PAT_KEY = 'NRLLM_TEST_GITHUB_PAT';

    private const SHAPED_PAT_VALUE = 'ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHAPED_STRIPE_KEY = 'NRLLM_TEST_STRIPE_LIVE';

    // Assembled rather than written out: a complete Stripe-shaped literal in a
    // committed file trips GitHub's push protection, even as an obvious fixture.
    private const SHAPED_STRIPE_VALUE = 'sk_live_' . '999999999999999999999999';

    private const SHAPED_JWT_KEY = 'NRLLM_TEST_SESSION_BLOB';

    private const SHAPED_JWT_VALUE = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcDEF123';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->fixtureEnv() as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->fixtureEnv()) as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function fixtureEnv(): array
    {
        return [
            self::PLAIN_KEY       => self::PLAIN_VALUE,
            self::SECRET_KEY      => self::SECRET_VALUE,
            self::PWD_KEY         => self::PWD_VALUE,
            self::URL_KEY         => self::URL_VALUE,
            self::NOUSER_URL_KEY  => self::NOUSER_URL_VALUE,
            self::SHAPED_PAT_KEY  => self::SHAPED_PAT_VALUE,
            self::SHAPED_STRIPE_KEY => self::SHAPED_STRIPE_VALUE,
            self::SHAPED_JWT_KEY  => self::SHAPED_JWT_VALUE,
        ];
    }

    /**
     * An environment variable named `5` reaches `maskValue()` as int 5, not as
     * the string it was written as: PHP casts a numeric-string array key to int,
     * and `collectEnvironment()` builds its map with `$env[self::toStr($name)]`.
     * Under strict_types a `string` parameter rejects that at the call, so the
     * tool died with a TypeError before listing anything -- for every variable,
     * not just the numeric one.
     *
     * Sets `$_ENV` directly rather than through fixtureEnv(): `putenv('5=…')` is
     * refused by some libc builds, and the array key is what the defect is about.
     */
    #[Test]
    public function aNumericVariableNameDoesNotBreakTheListing(): void
    {
        $restore = $this->overrideEnv('5', 'numeric-name-value');

        try {
            $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

            self::assertStringContainsString('5=numeric-name-value', $output);
            // The listing still carries everything else -- the TypeError took the
            // whole result, not one line.
            self::assertStringContainsString(self::PLAIN_KEY . '=' . self::PLAIN_VALUE, $output);
        } finally {
            $restore();
        }
    }

    /**
     * The name test still applies to a numeric-keyed variable: the cast happens
     * before the pattern match, not instead of it.
     */
    #[Test]
    public function aNumericNameIsStillTestedAgainstTheSecretPattern(): void
    {
        $restore = $this->overrideEnv('9_API_KEY', 'must-not-appear');

        try {
            $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

            self::assertStringContainsString('9_API_KEY=***redacted***', $output);
            self::assertStringNotContainsString('must-not-appear', $output);
        } finally {
            $restore();
        }
    }

    /**
     * Set one $_ENV entry and hand back the undo.
     *
     * Restores rather than unsets: an unconditional unset() in the teardown
     * removes a variable the process may have arrived with, for the whole
     * PHPUnit run, which is how one test starts deciding what a later one sees.
     *
     * @return callable(): void
     */
    private function overrideEnv(string $name, string $value): callable
    {
        $had      = \array_key_exists($name, $_ENV);
        $previous = $had ? $_ENV[$name] : null;

        $_ENV[$name] = $value;

        return static function () use ($name, $had, $previous): void {
            if ($had) {
                $_ENV[$name] = $previous;

                return;
            }

            unset($_ENV[$name]);
        };
    }

    #[Test]
    public function getSpecDeclaresGetEnvFunction(): void
    {
        $spec = (new GetEnvTool())->getSpec();

        self::assertSame('get_env', $spec->name);
        self::assertTrue((new GetEnvTool())->isEnabledByDefault());
    }

    #[Test]
    public function nonSecretValueIsShownButSecretValueIsRedacted(): void
    {
        $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

        self::assertStringContainsString(self::PLAIN_KEY . '=' . self::PLAIN_VALUE, $output);
        // The secret variable appears, but its value is masked.
        self::assertStringContainsString(self::SECRET_KEY . '=***redacted***', $output);
        self::assertStringNotContainsString(self::SECRET_VALUE, $output);
    }

    #[Test]
    public function pwdStyleSecretNameIsRedacted(): void
    {
        $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

        // MYSQL_PWD uses PWD, not PASS — it must still be caught.
        self::assertStringContainsString(self::PWD_KEY . '=***redacted***', $output);
        self::assertStringNotContainsString(self::PWD_VALUE, $output);
    }

    #[Test]
    public function inlineUrlCredentialsAreRedactedWhileHostRemains(): void
    {
        $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

        // The variable NAME is not secret-looking, but its VALUE embeds
        // credentials: the userinfo is stripped, the host/port kept for context.
        self::assertStringNotContainsString('s3cr3turl', $output);
        self::assertStringNotContainsString('cacheuser', $output);
        self::assertStringContainsString(self::URL_KEY . '=redis://***redacted***@cache-01:6379/0', $output);
    }

    #[Test]
    public function inlineUrlCredentialsWithEmptyUsernameAreRedacted(): void
    {
        $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

        // redis://:password@host has no username — the password must still be
        // stripped rather than leaking to the provider.
        self::assertStringNotContainsString('s3cr3tnouser', $output);
        self::assertStringContainsString(self::NOUSER_URL_KEY . '=redis://***redacted***@cache-02:6379/0', $output);
    }

    /**
     * The name rule cannot see these: nothing in GITHUB_PAT, STRIPE_LIVE or
     * SESSION_BLOB says secret, so before the value-shape pass all three values
     * were listed verbatim to the LLM provider.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function shapedSecretProvider(): iterable
    {
        yield 'GitHub PAT under a neutral name' => [self::SHAPED_PAT_KEY, self::SHAPED_PAT_VALUE];
        yield 'Stripe key under a neutral name' => [self::SHAPED_STRIPE_KEY, self::SHAPED_STRIPE_VALUE];
        yield 'JWT under a neutral name' => [self::SHAPED_JWT_KEY, self::SHAPED_JWT_VALUE];
    }

    #[Test]
    #[DataProvider('shapedSecretProvider')]
    public function aSecretShapedValueIsRedactedEvenWhenItsNameLooksHarmless(
        string $name,
        string $value,
    ): void {
        $output = (new GetEnvTool())->execute([], ToolExecutionContext::none())->content;

        self::assertStringNotContainsString($value, $output);
        self::assertStringContainsString($name . '=', $output, 'The variable itself should still be listed.');
    }

    #[Test]
    public function theNameRuleAloneWouldNotHaveCaughtTheseNames(): void
    {
        // Guards the premise of the test above: if someone later adds "PAT" or
        // "STRIPE" to the name pattern, these cases would start passing for the
        // wrong reason and stop covering the value-shape path.
        foreach ([self::SHAPED_PAT_KEY, self::SHAPED_STRIPE_KEY, self::SHAPED_JWT_KEY] as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/PASS|PASSWORD|PWD|SECRET|TOKEN|KEY|SALT|CREDENTIAL|AUTH|PRIVATE|MASTER|ENCRYPT|DSN|DATABASE_URL|APIKEY|API_KEY/i',
                $name,
                sprintf('"%s" is now matched by the NAME rule, so it no longer tests the value rule.', $name),
            );
        }
    }

    #[Test]
    public function requiresAdminIsTrue(): void
    {
        // Security invariant: get_env egresses host/cross-user data and must
        // stay admin-gated. Pin it so a refactor cannot silently flip it.
        self::assertTrue((new GetEnvTool())->requiresAdmin());
    }
}
