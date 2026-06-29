<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetPhpInfoTool.
 *
 * Asserts the curated subset (version, extensions, the few safe ini keys) is
 * present and that it stays curated — the full phpinfo() server/env dump must
 * NOT leak through this tool.
 */
#[CoversClass(GetPhpInfoTool::class)]
final class GetPhpInfoToolTest extends TestCase
{
    #[Test]
    public function getSpecDeclaresGetPhpInfoFunction(): void
    {
        self::assertSame('get_php_info', (new GetPhpInfoTool())->getSpec()->name);
        self::assertTrue((new GetPhpInfoTool())->isEnabledByDefault());
    }

    #[Test]
    public function returnsCuratedRuntimeSubset(): void
    {
        $output = (new GetPhpInfoTool())->execute([]);

        self::assertStringContainsString('PHP version: ' . PHP_VERSION, $output);
        self::assertStringContainsString('Loaded extensions (', $output);
        self::assertStringContainsString('Core', $output);
        self::assertStringContainsString('memory_limit = ', $output);
        self::assertStringContainsString('date.timezone = ', $output);

        // Curated — not a full phpinfo() dump of the server environment.
        self::assertStringNotContainsString('$_SERVER', $output);
        self::assertStringNotContainsString('phpinfo()', $output);
    }
}
