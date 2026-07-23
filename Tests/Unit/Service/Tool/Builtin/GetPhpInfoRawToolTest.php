<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoRawTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetPhpInfoRawTool.
 *
 * The raw variant captures the full phpinfo(INFO_ALL) output and is disabled by
 * default — both are asserted here.
 */
#[CoversClass(GetPhpInfoRawTool::class)]
final class GetPhpInfoRawToolTest extends TestCase
{
    #[Test]
    public function isDisabledByDefault(): void
    {
        self::assertFalse((new GetPhpInfoRawTool())->isEnabledByDefault());
        self::assertSame('get_php_info_raw', (new GetPhpInfoRawTool())->getSpec()->name);
    }

    #[Test]
    public function capturesFullPhpInfoOutput(): void
    {
        $output = (new GetPhpInfoRawTool())->execute([], ToolExecutionContext::none())->content;

        // phpinfo() always emits the PHP version line in its capture; in CLI it
        // is plain text ("PHP Version => x.y.z").
        self::assertStringContainsString('PHP Version', $output);
        self::assertStringContainsString(PHP_VERSION, $output);
        self::assertNotSame('phpinfo unavailable.', $output);
    }
}
