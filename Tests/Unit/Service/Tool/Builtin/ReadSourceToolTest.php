<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\ReadSourceTool;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(ReadSourceTool::class)]
final class ReadSourceToolTest extends TestCase
{
    private string $root;

    private ReadSourceTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nrllm-readsource-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/Classes', 0o777, true);
        mkdir($this->root . '/config/system', 0o777, true);
        file_put_contents(
            $this->root . '/Classes/Demo.php',
            implode("\n", array_map(static fn(int $i): string => sprintf('// line %d', $i), range(1, 10))),
        );
        file_put_contents($this->root . '/config/system/settings.php', '<?php // secret');

        $this->tool = new ReadSourceTool(new SourcePathGuard($this->root));
    }

    #[Test]
    public function specShape(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('read_source', $spec->name);
        self::assertSame(['path'], $spec->parameters['required'] ?? null);
        self::assertTrue($this->tool->requiresAdmin());
        self::assertTrue($this->tool->isEnabledByDefault());
        self::assertSame('code', $this->tool->getGroup());
    }

    #[Test]
    public function readsARangeWithLineNumbers(): void
    {
        $output = $this->tool->execute(['path' => 'Classes/Demo.php', 'from_line' => 3, 'lines' => 2]);

        self::assertStringContainsString('Classes/Demo.php (lines 3-4 of 10):', $output);
        self::assertStringContainsString('    3 | // line 3', $output);
        self::assertStringContainsString('    4 | // line 4', $output);
        self::assertStringNotContainsString('// line 5', $output);
    }

    #[Test]
    public function deniesSettingsPhp(): void
    {
        $output = $this->tool->execute(['path' => 'config/system/settings.php']);

        self::assertStringContainsString('Denied or not found', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    #[Test]
    public function requiresAPath(): void
    {
        self::assertStringContainsString('"path" is required', $this->tool->execute([]));
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->root, true);
        parent::tearDown();
    }
}
