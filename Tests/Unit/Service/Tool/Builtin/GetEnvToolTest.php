<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvTool;
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

    protected function setUp(): void
    {
        parent::setUp();
        putenv(self::PLAIN_KEY . '=' . self::PLAIN_VALUE);
        putenv(self::SECRET_KEY . '=' . self::SECRET_VALUE);
        $_ENV[self::PLAIN_KEY]  = self::PLAIN_VALUE;
        $_ENV[self::SECRET_KEY] = self::SECRET_VALUE;
    }

    protected function tearDown(): void
    {
        putenv(self::PLAIN_KEY);
        putenv(self::SECRET_KEY);
        unset($_ENV[self::PLAIN_KEY], $_ENV[self::SECRET_KEY]);
        parent::tearDown();
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
        $output = (new GetEnvTool())->execute([]);

        self::assertStringContainsString(self::PLAIN_KEY . '=' . self::PLAIN_VALUE, $output);
        // The secret variable appears, but its value is masked.
        self::assertStringContainsString(self::SECRET_KEY . '=***redacted***', $output);
        self::assertStringNotContainsString(self::SECRET_VALUE, $output);
    }
}
