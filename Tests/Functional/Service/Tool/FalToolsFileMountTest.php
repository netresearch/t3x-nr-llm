<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * File-mount confinement of the sys_file-querying FAL tools for non-admins
 * (ADR-047): search_fal_files, find_missing_files and get_fal_references gate
 * only the STORAGE via {@see \Netresearch\NrLlm\Service\Tool\FalStorageGate};
 * this test proves the added per-file mount check actually confines a
 * subfolder-mounted editor to their mount.
 *
 * Mirrors BrowseFalFolderToolFileMountTest: the storage ROW is inserted
 * directly so the ResourceStorage object is first built while the editor is
 * logged in inside a backend request, when the core StoragePermissionsAspect
 * attaches the user's file mounts — only then does checkFileActionPermission()
 * enforce them.
 */
final class FalToolsFileMountTest extends AbstractFunctionalTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/top-secret.txt', 'root file');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $storageConnection = $connectionPool->getConnectionForTable('sys_file_storage');
        self::assertInstanceOf(Connection::class, $storageConnection);
        // Row only — the ResourceStorage OBJECT must not exist before login.
        $storageConnection->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $storageConnection->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/',
        ]);
        $storageConnection->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Doc readers', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups.
        $storageConnection->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $fileConnection = $connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $fileConnection);
        // In-mount (/docs/) and out-of-mount (root) indexed files.
        $this->indexFile($fileConnection, 10, '/docs/manual.txt', 'manual.txt', 0);
        $this->indexFile($fileConnection, 11, '/top-secret.txt', 'top-secret.txt', 0);
        // Missing files, one per side of the mount boundary.
        $this->indexFile($fileConnection, 12, '/docs/gone-inside.txt', 'gone-inside.txt', 1);
        $this->indexFile($fileConnection, 13, '/gone-outside.txt', 'gone-outside.txt', 1);
    }

    protected function tearDown(): void
    {
        // The faked backend request must not leak into later tests.
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private function indexFile(Connection $connection, int $uid, string $identifier, string $name, int $missing): void
    {
        $connection->insert('sys_file', [
            'uid'             => $uid,
            'pid'             => 0,
            'storage'         => 1,
            'identifier'      => $identifier,
            'identifier_hash' => sha1($identifier),
            'folder_hash'     => sha1(dirname($identifier)),
            'name'            => $name,
            'extension'       => 'txt',
            'mime_type'       => 'text/plain',
            'size'            => 10,
            'missing'         => $missing,
        ]);
    }

    private function loginEditorInBackendRequest(): void
    {
        $this->setUpBackendUser(2);
        // The core StoragePermissionsAspect only attaches mounts/permissions
        // when the storage object is created inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
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
    public function searchFalFilesHidesFilesOutsideTheUsersMount(): void
    {
        $this->loginEditorInBackendRequest();

        $output = $this->tool('search_fal_files')->execute(['query' => 'txt']);

        self::assertStringContainsString('docs/manual.txt', $output);
        self::assertStringNotContainsString('top-secret.txt', $output);
    }

    #[Test]
    public function findMissingFilesHidesFilesOutsideTheUsersMount(): void
    {
        $this->loginEditorInBackendRequest();

        $output = $this->tool('find_missing_files')->execute([]);

        self::assertStringContainsString('docs/gone-inside.txt', $output);
        self::assertStringNotContainsString('gone-outside.txt', $output);
    }

    #[Test]
    public function getFalReferencesDeniesAFileOutsideTheUsersMount(): void
    {
        $this->loginEditorInBackendRequest();

        // File 11 (root, out of the /docs/ mount) must not reveal its usages.
        $output = $this->tool('get_fal_references')->execute(['uid' => 11]);

        self::assertSame('Asset not found or not permitted.', $output);
    }

    #[Test]
    public function getFalReferencesAllowsAFileInsideTheUsersMount(): void
    {
        $this->loginEditorInBackendRequest();

        // File 10 (/docs/, in mount) is reachable — no references seeded, so the
        // neutral "no references" answer (not the permission denial) is returned.
        $output = $this->tool('get_fal_references')->execute(['uid' => 10]);

        self::assertStringContainsString('No references to manual.txt', $output);
    }
}
