<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\BrowseFalFolderTool;
use Netresearch\NrLlm\Service\Tool\Builtin\FindMissingFilesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFalReferencesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListFalStoragesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchFalFilesTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Spec shape + admin/default flags of the FAL tools (ADR-047). The storage
 * and DB paths are covered functionally. getSpec()/requiresAdmin()/
 * isEnabledByDefault() touch no collaborator, so
 * newInstanceWithoutConstructor is safe.
 */
#[CoversClass(BrowseFalFolderTool::class)]
#[CoversClass(FindMissingFilesTool::class)]
#[CoversClass(GetFalReferencesTool::class)]
#[CoversClass(ListFalStoragesTool::class)]
#[CoversClass(SearchFalFilesTool::class)]
final class FalToolsSpecTest extends TestCase
{
    /**
     * @return list<class-string<ToolInterface>>
     */
    private static function toolClasses(): array
    {
        return [
            ListFalStoragesTool::class,
            BrowseFalFolderTool::class,
            SearchFalFilesTool::class,
            GetFalReferencesTool::class,
            FindMissingFilesTool::class,
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
    public function specsExposeNamesAndRequiredParameters(): void
    {
        $storages = self::bare(ListFalStoragesTool::class)->getSpec();
        self::assertSame('list_fal_storages', $storages->name);
        self::assertArrayNotHasKey('required', $storages->parameters);

        $browse = self::bare(BrowseFalFolderTool::class)->getSpec();
        self::assertSame('browse_fal_folder', $browse->name);
        self::assertArrayNotHasKey('required', $browse->parameters);

        $search = self::bare(SearchFalFilesTool::class)->getSpec();
        self::assertSame('search_fal_files', $search->name);
        self::assertSame(['query'], $search->parameters['required'] ?? []);

        $references = self::bare(GetFalReferencesTool::class)->getSpec();
        self::assertSame('get_fal_references', $references->name);
        self::assertSame(['uid'], $references->parameters['required'] ?? []);

        $missing = self::bare(FindMissingFilesTool::class)->getSpec();
        self::assertSame('find_missing_files', $missing->name);
        self::assertArrayNotHasKey('required', $missing->parameters);
    }

    #[Test]
    public function allToolsAreNonAdminEnabledByDefaultAndInTheFilesGroup(): void
    {
        foreach (self::toolClasses() as $class) {
            $tool = self::bare($class);
            self::assertFalse($tool->requiresAdmin(), $class);
            self::assertTrue($tool->isEnabledByDefault(), $class);
            self::assertSame('files', $tool->getGroup(), $class);
        }
    }

    #[Test]
    public function failClosedWithoutBackendUser(): void
    {
        // No $GLOBALS['BE_USER'] in unit context → the storage gate yields
        // no storages and every tool answers with its neutral denial.
        self::assertSame(
            'Asset not found or not permitted.',
            self::bare(GetFalReferencesTool::class)->execute(['uid' => 0], ToolExecutionContext::none())->content,
        );
        self::assertSame(
            'Error: "query" is required.',
            self::bare(SearchFalFilesTool::class)->execute([], ToolExecutionContext::none())->content,
        );
    }
}
