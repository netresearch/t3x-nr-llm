<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\BrowseFalFolderTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * File-mount confinement of browse_fal_folder for non-admins (ADR-047).
 *
 * The storage ROW is inserted directly (no object instantiation) so the
 * ResourceStorage object is first created while the editor is logged in —
 * only then does the core StoragePermissionsAspect attach the user's file
 * mounts and permissions to the storage object, exactly as in a real
 * backend request. Outside a backend request the aspect never runs and the
 * tool's own setEvaluatePermissions(true) makes non-admin access fail
 * CLOSED (everything denied) rather than mount-blind.
 */
#[CoversClass(BrowseFalFolderTool::class)]
final class BrowseFalFolderToolFileMountTest extends AbstractFunctionalTestCase
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

    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/top-secret.txt', 'root file');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable('sys_file_storage');
        self::assertInstanceOf(Connection::class, $connection);

        // Row only — the ResourceStorage OBJECT must not exist before login.
        $connection->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $connection->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/',
        ]);
        $connection->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Doc readers', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups.
        $connection->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('browse_fal_folder');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    protected function tearDown(): void
    {
        // The faked backend request must not leak into later tests.
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function nonAdminIsConfinedToTheirFileMount(): void
    {
        $this->setUpBackendUser(2);
        // The core StoragePermissionsAspect only attaches mounts/permissions
        // when the storage object is created inside a BACKEND request — set
        // the request context the real tool-loop (backend AJAX) runs in.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        // The storage root lies outside the /docs/ mount — denied, and the
        // root file's name never egresses.
        $rootOutput = $this->tool->execute(['folder' => '/']);
        self::assertSame('Folder not found or not permitted.', $rootOutput);

        // The mounted folder lists.
        $output = $this->tool->execute(['folder' => '/docs/']);
        self::assertStringContainsString('- manual.txt (text/plain, 10 B)', $output);
        self::assertStringNotContainsString('top-secret', $output);
    }
}
