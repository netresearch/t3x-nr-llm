<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvRawTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetEnvRawTool.
 *
 * The raw variant performs NO redaction (that is the whole point) and is
 * therefore disabled by default — both contracts are asserted here.
 */
#[CoversClass(GetEnvRawTool::class)]
final class GetEnvRawToolTest extends TestCase
{
    private const SECRET_KEY = 'NRLLM_TEST_RAW_SECRET_KEY';
    private const SECRET_VALUE = 'raw-unmasked-value';

    protected function setUp(): void
    {
        parent::setUp();
        putenv(self::SECRET_KEY . '=' . self::SECRET_VALUE);
        $_ENV[self::SECRET_KEY] = self::SECRET_VALUE;
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET_KEY);
        unset($_ENV[self::SECRET_KEY]);
        parent::tearDown();
    }

    #[Test]
    public function isDisabledByDefault(): void
    {
        self::assertFalse((new GetEnvRawTool())->isEnabledByDefault());
        self::assertSame('get_env_raw', (new GetEnvRawTool())->getSpec()->name);
    }

    #[Test]
    public function returnsSecretValueUnredacted(): void
    {
        $output = (new GetEnvRawTool())->execute([])->content;

        self::assertStringContainsString(self::SECRET_KEY . '=' . self::SECRET_VALUE, $output);
    }
}
