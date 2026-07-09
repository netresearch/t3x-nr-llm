<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\BrowseFalFolderTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CheckTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\Builtin\FetchLogsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\FindMissingFilesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\FluidResolveTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFalReferencesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFlexFormSchemaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFullTcaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetLastExceptionTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageTreeTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetRecordHistoryTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTableSchemaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTcaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTsConfigTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeGroupsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListFalStoragesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadSourceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolveUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchCodeTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchFalFilesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ValidateTcaTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the curated group taxonomy of every built-in tool (ADR-043).
 *
 * getGroup() is a constant return on every builtin, so instantiating via
 * newInstanceWithoutConstructor is safe — no collaborator is touched.
 */
#[CoversNothing]
final class BuiltinToolGroupsTest extends TestCase
{
    /**
     * @return array<string, array{class-string<ToolInterface>, string}>
     */
    public static function taxonomy(): array
    {
        return [
            'search_records'    => [SearchRecordsTool::class, 'content'],
            'get_page_content'  => [GetPageContentTool::class, 'content'],
            'read_records'      => [ReadRecordsTool::class, 'content'],
            'get_pagetree'      => [GetPageTreeTool::class, 'structure'],
            'get_tca'           => [GetTcaTool::class, 'structure'],
            'get_full_tca'      => [GetFullTcaTool::class, 'structure'],
            'get_table_schema'  => [GetTableSchemaTool::class, 'structure'],
            'get_flexform_schema'    => [GetFlexFormSchemaTool::class, 'structure'],
            'fluid_resolve'     => [FluidResolveTool::class, 'configuration'],
            'read_fal_asset_meta'    => [ReadFalAssetMetaTool::class, 'structure'],
            'get_env'           => [GetEnvTool::class, 'system'],
            'get_env_raw'       => [GetEnvRawTool::class, 'system'],
            'get_php_info'      => [GetPhpInfoTool::class, 'system'],
            'get_php_info_raw'  => [GetPhpInfoRawTool::class, 'system'],
            'fetch_logs'        => [FetchLogsTool::class, 'system'],
            'list_be_users'     => [ListBeUsersTool::class, 'accounts'],
            'list_be_users_raw' => [ListBeUsersRawTool::class, 'accounts'],
            'list_be_groups'    => [ListBeGroupsTool::class, 'accounts'],
            'get_typoscript'    => [GetTypoScriptTool::class, 'configuration'],
            'get_tsconfig'      => [GetTsConfigTool::class, 'configuration'],
            'get_record_history' => [GetRecordHistoryTool::class, 'content'],
            'resolve_url'       => [ResolveUrlTool::class, 'structure'],
            'validate_tca'      => [ValidateTcaTool::class, 'structure'],
            'check_typoscript'  => [CheckTypoScriptTool::class, 'configuration'],
            'get_last_exception' => [GetLastExceptionTool::class, 'code'],
            'read_source'       => [ReadSourceTool::class, 'code'],
            'search_code'       => [SearchCodeTool::class, 'code'],
            'probe_url'         => [ProbeUrlTool::class, 'system'],
            'list_fal_storages' => [ListFalStoragesTool::class, 'files'],
            'browse_fal_folder' => [BrowseFalFolderTool::class, 'files'],
            'search_fal_files'  => [SearchFalFilesTool::class, 'files'],
            'get_fal_references' => [GetFalReferencesTool::class, 'files'],
            'find_missing_files' => [FindMissingFilesTool::class, 'files'],
        ];
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('taxonomy')]
    public function builtinDeclaresItsCuratedGroup(string $class, string $expectedGroup): void
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertSame($expectedGroup, $tool->getGroup());
    }
}
