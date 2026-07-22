<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetLastExceptionTool;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(GetLastExceptionTool::class)]
final class GetLastExceptionToolTest extends TestCase
{
    private string $base;

    private GetLastExceptionTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/nrllm-lastexc-' . bin2hex(random_bytes(4));
        $project    = $this->base . '/project';
        $logDir     = $this->base . '/log';
        mkdir($project . '/Classes', 0o777, true);
        mkdir($logDir, 0o777, true);

        // The failing source file the stack frame points at.
        file_put_contents(
            $project . '/Classes/Broken.php',
            implode("\n", array_map(static fn(int $i): string => sprintf('// code line %d', $i), range(1, 20))),
        );

        $frameFile     = $project . '/Classes/Broken.php';
        $exceptionJson = json_encode([
            'exception' => "RuntimeException: kaboom in {$frameFile}:10\n"
                . "Stack trace:\n"
                . "#0 {$frameFile}(10): explode()\n"
                . "#1 {$project}/vendor/typo3/core/Http.php(5): dispatch()",
        ], JSON_THROW_ON_ERROR);
        file_put_contents(
            $logDir . '/typo3_test.log',
            'Tue, 08 Jul 2026 10:00:00 +0200 [ERROR] request="r1" component="Acme.Demo": it broke - '
            . $exceptionJson . "\n",
        );

        $this->tool = new GetLastExceptionTool(
            new LogExceptionReader($logDir),
            new SourcePathGuard($project),
        );
    }

    #[Test]
    public function specShape(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('get_last_exception', $spec->name);
        self::assertTrue($this->tool->requiresAdmin());
        self::assertTrue($this->tool->isEnabledByDefault());
        self::assertSame('code', $this->tool->getGroup());
    }

    #[Test]
    public function rendersExceptionWithSourceContextAroundProjectFrames(): void
    {
        $output = $this->tool->execute([])->content;

        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('it broke', $output);
        self::assertStringContainsString('Stack trace:', $output);
        // The failing line is marked and surrounded by context.
        self::assertStringContainsString('>   10 | // code line 10', $output);
        self::assertStringContainsString('     4 | // code line 4', $output);
        self::assertStringContainsString('    16 | // code line 16', $output);
    }

    #[Test]
    public function indexOutOfRangeIsExplained(): void
    {
        self::assertStringContainsString('out of range', $this->tool->execute(['index' => 5])->content);
    }

    #[Test]
    public function noEntriesHintsAtFetchLogs(): void
    {
        $empty = $this->base . '/empty-log';
        mkdir($empty, 0o777, true);
        $tool = new GetLastExceptionTool(new LogExceptionReader($empty), new SourcePathGuard($this->base . '/project'));

        $output = $tool->execute([])->content;

        self::assertStringContainsString('No error-level entries', $output);
        self::assertStringContainsString('fetch_logs', $output);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->base, true);
        parent::tearDown();
    }
}
