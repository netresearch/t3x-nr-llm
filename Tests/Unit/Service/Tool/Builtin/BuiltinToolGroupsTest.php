<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\FetchLogsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetEnvTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetLastExceptionTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageTreeTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPhpInfoTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTcaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTsConfigTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeGroupsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersRawTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadSourceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchCodeTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
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
            'get_last_exception' => [GetLastExceptionTool::class, 'code'],
            'read_source'       => [ReadSourceTool::class, 'code'],
            'search_code'       => [SearchCodeTool::class, 'code'],
            'probe_url'         => [ProbeUrlTool::class, 'system'],
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
