<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetSystemStatusTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListDeprecationsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListMiddlewaresTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListSchedulerTasksTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the status, scheduler, deprecation and middleware
 * diagnostics tools (ADR-048).
 */
#[CoversClass(GetSystemStatusTool::class)]
#[CoversClass(ListDeprecationsTool::class)]
#[CoversClass(ListMiddlewaresTool::class)]
#[CoversClass(ListSchedulerTasksTool::class)]
final class DiagnosticsToolsFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
    }

    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }

    #[Test]
    public function systemStatusReportsVersionsWithoutPaths(): void
    {
        $output = $this->tool('get_system_status')->execute([])->content;

        self::assertStringContainsString('TYPO3: ' . (new Typo3Version())->getVersion(), $output);
        self::assertStringContainsString('PHP: ' . PHP_VERSION, $output);
        self::assertStringContainsString('Database: ', $output);
        self::assertStringContainsString('Composer mode: ', $output);
        // No filesystem detail egresses.
        self::assertStringNotContainsString($this->instancePath, $output);
        self::assertStringNotContainsString('/var/', $output);
    }

    #[Test]
    public function schedulerAbsenceDegradesGracefully(): void
    {
        // EXT:scheduler is not part of the test instance.
        self::assertSame('Scheduler is not installed.', $this->tool('list_scheduler_tasks')->execute([])->content);
    }

    #[Test]
    public function deprecationsAreDedupedAndPathsRelativized(): void
    {
        $logDir = $this->instancePath . '/typo3temp/var/tests/deprecations';
        GeneralUtility::mkdir_deep($logDir);
        $projectFile = Environment::getProjectPath() . '/vendor/acme/thing/Classes/Old.php';
        file_put_contents($logDir . '/typo3_deprecations_test.log', implode("\n", [
            'Tue, 08 Jul 2026 12:00:00 +0000 [NOTICE] request="a1" component="TYPO3.CMS.deprecations": Method foo() is deprecated',
            'Tue, 08 Jul 2026 12:00:01 +0000 [NOTICE] request="a2" component="TYPO3.CMS.deprecations": Method foo() is deprecated',
            'Tue, 08 Jul 2026 12:00:02 +0000 [NOTICE] request="a3" component="TYPO3.CMS.deprecations": Class deprecated in ' . $projectFile,
        ]) . "\n");

        $output = (new ListDeprecationsTool($logDir))->execute([])->content;

        self::assertStringContainsString('Method foo() is deprecated (×2)', $output);
        self::assertStringContainsString('vendor/acme/thing/Classes/Old.php', $output);
        self::assertStringNotContainsString(Environment::getProjectPath() . '/vendor', $output);
    }

    #[Test]
    public function missingDeprecationLogDegradesGracefully(): void
    {
        $emptyDir = $this->instancePath . '/typo3temp/var/tests/no-deprecations';
        GeneralUtility::mkdir_deep($emptyDir);

        self::assertSame(
            'No deprecation log found (the deprecation channel may be disabled).',
            (new ListDeprecationsTool($emptyDir))->execute([])->content,
        );
    }

    #[Test]
    public function middlewareStackListsBackendMiddlewares(): void
    {
        $output = $this->tool('list_middlewares')->execute(['stack' => 'backend'])->content;

        self::assertStringContainsString('PSR-15 backend middleware stack (', $output);
        self::assertStringContainsString('typo3/cms-core/normalized-params-attribute', $output);
        self::assertMatchesRegularExpression('/\(TYPO3\\\\CMS\\\\[A-Za-z\\\\]+\)/', $output);
    }

    #[Test]
    public function unknownMiddlewareStackIsRejected(): void
    {
        self::assertStringContainsString(
            'Unknown stack',
            $this->tool('list_middlewares')->execute(['stack' => 'cli'])->content,
        );
    }
}
