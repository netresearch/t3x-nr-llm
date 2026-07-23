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
        ];
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

    #[Test]
    public function requiresAdminIsTrue(): void
    {
        // Security invariant: get_env egresses host/cross-user data and must
        // stay admin-gated. Pin it so a refactor cannot silently flip it.
        self::assertTrue((new GetEnvTool())->requiresAdmin());
    }
}
