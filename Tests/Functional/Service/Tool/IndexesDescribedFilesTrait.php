<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\DescribeFalAssetTool;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The storage, mount and file fixture of the `describe_fal_asset` tests: one
 * local storage, a `/docs/` file mount granted to the editor (uid 2) through
 * group 9, and files indexed by hand. The recipe is the one
 * {@see UpdateFalAssetMetaToolFileMountTest} uses.
 *
 * Needs `AbstractFunctionalTestCase` in the using class.
 */
trait IndexesDescribedFilesTrait
{
    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath"><value index="vDEF">fileadmin/</value></field>
                <field index="pathType"><value index="vDEF">relative</value></field>
                <field index="caseSensitive"><value index="vDEF">1</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>';

    private function setUpStorageWithEditorMount(): void
    {
        $this->importFixture('BeUsers.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');

        $connection = $this->pool()->getConnectionForTable('sys_file_storage');
        // Row only — the ResourceStorage object must not exist before login.
        $connection->insert('sys_file_storage', [
            'uid'           => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $connection->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/', 'read_only' => 1,
        ]);
        $connection->insert('be_groups', [
            'uid'              => 9, 'pid' => 0, 'title' => 'Doc readers', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups.
        $connection->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);
    }

    /**
     * Writes the file to disk and indexes it into `sys_file`.
     */
    private function indexFile(int $uid, string $identifier): void
    {
        file_put_contents($this->instancePath . '/fileadmin' . $identifier, 'content of ' . $uid);

        $this->pool()->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'             => $uid,
            'pid'             => 0,
            'storage'         => 1,
            'identifier'      => $identifier,
            'identifier_hash' => 'fixture-' . $uid,
            'folder_hash'     => 'fixture-folder-' . $uid,
            'name'            => basename($identifier),
            'extension'       => 'txt',
            'mime_type'       => 'text/plain',
            'size'            => 10,
            'type'            => 1,
            'missing'         => 0,
        ]);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function indexMetadata(int $uid, int $fileUid, array $values): void
    {
        $this->pool()->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid'              => $uid,
            'pid'              => 0,
            'file'             => $fileUid,
            'sys_language_uid' => 0,
            ...$values,
        ]);
    }

    /**
     * The editor, with the field grants given, inside a backend request.
     */
    private function editor(string $nonExcludeFields = ''): BackendUserAuthentication
    {
        $user                                  = $this->setUpBackendUser(2);
        $user->groupData['non_exclude_fields'] = $nonExcludeFields;
        $GLOBALS['TYPO3_REQUEST']              = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $user;
    }

    private function describeTool(): DescribeFalAssetTool
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        // From the registry, not `new`: the storage gate only enforces mounts
        // with the DI-wired StorageRepository behind it.
        $tool = $registry->get('describe_fal_asset');
        self::assertInstanceOf(DescribeFalAssetTool::class, $tool);

        return $tool;
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
