<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetSiteConfigTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetSystemStatusTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListDeprecationsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListExtensionsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListMiddlewaresTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListSchedulerTasksTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Spec shape + admin/default flags of the diagnostics tools (ADR-048). The
 * runtime paths are covered functionally. getSpec()/requiresAdmin()/
 * isEnabledByDefault() touch no collaborator, so
 * newInstanceWithoutConstructor is safe.
 */
#[CoversClass(GetSiteConfigTool::class)]
#[CoversClass(GetSystemStatusTool::class)]
#[CoversClass(ListDeprecationsTool::class)]
#[CoversClass(ListExtensionsTool::class)]
#[CoversClass(ListMiddlewaresTool::class)]
#[CoversClass(ListSchedulerTasksTool::class)]
final class DiagnosticsToolsSpecTest extends TestCase
{
    /**
     * @return list<class-string<ToolInterface>>
     */
    private static function toolClasses(): array
    {
        return [
            ListExtensionsTool::class,
            GetSiteConfigTool::class,
            ListSchedulerTasksTool::class,
            GetSystemStatusTool::class,
            ListDeprecationsTool::class,
            ListMiddlewaresTool::class,
        ];
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    private static function bare(string $class): ToolInterface
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }

    #[Test]
    public function specsExposeNamesAndNoRequiredParameters(): void
    {
        $expected = [
            ListExtensionsTool::class     => 'list_extensions',
            GetSiteConfigTool::class      => 'get_site_config',
            ListSchedulerTasksTool::class => 'list_scheduler_tasks',
            GetSystemStatusTool::class    => 'get_system_status',
            ListDeprecationsTool::class   => 'list_deprecations',
            ListMiddlewaresTool::class    => 'list_middlewares',
        ];

        foreach ($expected as $class => $name) {
            $spec = self::bare($class)->getSpec();
            self::assertSame($name, $spec->name);
            // All arguments are optional across the whole wave.
            self::assertArrayNotHasKey('required', $spec->parameters);
        }
    }

    #[Test]
    public function allAreAdminOnlyAndEnabledByDefault(): void
    {
        foreach (self::toolClasses() as $class) {
            $tool = self::bare($class);
            self::assertTrue($tool->requiresAdmin(), $class);
            self::assertTrue($tool->isEnabledByDefault(), $class);
        }
    }

    #[Test]
    public function groupsFollowTheTaxonomy(): void
    {
        foreach (self::toolClasses() as $class) {
            $tool     = self::bare($class);
            $expected = $class === GetSiteConfigTool::class ? 'configuration' : 'system';
            self::assertSame($expected, $tool->getGroup(), $class);
        }
    }
}
