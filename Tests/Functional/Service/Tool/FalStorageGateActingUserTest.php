<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The per-file mount check follows the user it is PASSED, not the one who
 * happens to be in `$GLOBALS['BE_USER']` (#672).
 *
 * Two editors with disjoint mounts on the same storage make the difference
 * observable in both directions. Every test here logs one in and asks the gate
 * about the other; on the previous implementation — which read the
 * request-shared `ResourceStorage` whose mounts the core aspect attached for
 * the ambient user — each of the two assertions fails the opposite way: the
 * acting user's own file is refused, and a file only the ambient user may
 * reach is permitted.
 *
 * That is not a hypothetical arrangement. On the approval path the run resumes
 * under the OWNER's identity (ADR-083) while the ambient user is the approver.
 */
#[CoversClass(FalStorageGate::class)]
final class FalStorageGateActingUserTest extends AbstractFunctionalTestCase
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

    /** Mounted by DOCS_EDITOR only. */
    private const DOCS_FILE = '/docs/manual.txt';

    /** Mounted by PRIVATE_EDITOR only. */
    private const PRIVATE_FILE = '/private/notes.txt';

    private const DOCS_EDITOR = 2;

    private const PRIVATE_EDITOR = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/private');
        file_put_contents($this->instancePath . self::DOCS_FILE_PATH, 'The manual');
        file_put_contents($this->instancePath . self::PRIVATE_FILE_PATH, 'Private notes');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $connection = $connectionPool->getConnectionForTable('sys_file_storage');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);

        // Two mounts on the SAME storage, disjoint. The storage allow-list
        // cannot tell them apart — only the mount check can.
        $connection->insert('sys_filemounts', ['uid' => 1, 'pid' => 0, 'title' => 'Docs', 'identifier' => '1:/docs/']);
        $connection->insert('sys_filemounts', ['uid' => 2, 'pid' => 0, 'title' => 'Private', 'identifier' => '1:/private/']);

        $connection->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Docs readers',
            'file_mountpoints' => '1', 'file_permissions' => 'readFolder,readFile',
        ]);
        $connection->insert('be_groups', [
            'uid' => 10, 'pid' => 0, 'title' => 'Private readers',
            'file_mountpoints' => '2', 'file_permissions' => 'readFolder,readFile',
        ]);

        // options=3: inherit db AND file mounts from groups.
        $connection->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => self::DOCS_EDITOR]);
        $connection->insert('be_users', [
            'uid' => self::PRIVATE_EDITOR, 'pid' => 0, 'username' => 'private-editor',
            'password' => '$2y$12$RJxT/2Y6d3oRm5d5ohJi9.CmZlVW.7N4vMHXiQ/D6xJBPfqfNMpBm',
            'admin' => 0, 'usergroup' => '10', 'options' => 3, 'disable' => 0, 'deleted' => 0,
        ]);

        $fileConnection = $connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $fileConnection);
        $this->indexFile($fileConnection, 10, self::DOCS_FILE, 'manual.txt');
        $this->indexFile($fileConnection, 11, self::PRIVATE_FILE, 'notes.txt');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private const DOCS_FILE_PATH = '/fileadmin/docs/manual.txt';

    private const PRIVATE_FILE_PATH = '/fileadmin/private/notes.txt';

    #[Test]
    public function theActingUsersOwnFileIsReachableWhileAnotherUserIsAmbient(): void
    {
        $acting = $this->userWithoutLogin(self::DOCS_EDITOR);
        $this->loginInBackendRequest(self::PRIVATE_EDITOR);

        self::assertTrue($this->gate()->isFileAccessible($acting, 1, self::DOCS_FILE));
    }

    #[Test]
    public function aFileOnlyTheAmbientUserMayReachIsRefusedForTheActingUser(): void
    {
        $acting = $this->userWithoutLogin(self::DOCS_EDITOR);
        $this->loginInBackendRequest(self::PRIVATE_EDITOR);

        // The reverse direction, and the one that matters for a write: the
        // ambient user's broader reach must not become the acting user's.
        self::assertFalse($this->gate()->isFileAccessible($acting, 1, self::PRIVATE_FILE));
    }

    #[Test]
    public function theSameTwoVerdictsHoldWithTheRolesSwapped(): void
    {
        $acting = $this->userWithoutLogin(self::PRIVATE_EDITOR);
        $this->loginInBackendRequest(self::DOCS_EDITOR);

        $gate = $this->gate();
        self::assertTrue($gate->isFileAccessible($acting, 1, self::PRIVATE_FILE));
        self::assertFalse($gate->isFileAccessible($acting, 1, self::DOCS_FILE));
    }

    /**
     * Sanity: with one user in both roles the gate answers as it always did.
     * Without this, a gate that refused everything would satisfy half the
     * assertions above.
     */
    #[Test]
    public function anEditorActingInTheirOwnRequestKeepsTheirOwnMount(): void
    {
        $user = $this->loginInBackendRequest(self::DOCS_EDITOR);

        $gate = $this->gate();
        self::assertTrue($gate->isFileAccessible($user, 1, self::DOCS_FILE));
        self::assertFalse($gate->isFileAccessible($user, 1, self::PRIVATE_FILE));
    }

    private function gate(): FalStorageGate
    {
        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);

        return $gate;
    }

    /**
     * The acting user, resolved WITHOUT becoming the ambient one — the shape
     * `ActingBackendUserResolver` produces on the approval path.
     */
    private function userWithoutLogin(int $uid): BackendUserAuthentication
    {
        $ambient = $GLOBALS['BE_USER'] ?? null;
        $user    = $this->setUpBackendUser($uid);

        if ($ambient !== null) {
            $GLOBALS['BE_USER'] = $ambient;
        }

        return $user;
    }

    private function loginInBackendRequest(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        // The core StoragePermissionsAspect only attaches mounts to a storage
        // built inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        // Force the request-shared storage into existence NOW, under this user.
        // Without it the first gate call would create it, and the defect this
        // test describes would depend on call order rather than on the bug.
        $repository = $this->get(StorageRepository::class);
        self::assertInstanceOf(StorageRepository::class, $repository);
        $repository->findByUid(1);

        return $user;
    }

    private function indexFile(Connection $connection, int $uid, string $identifier, string $name): void
    {
        // TYPO3's own sha1 identifier-hash algorithm, so getFile() resolves the
        // index row exactly as core does. A fixture, not a security context.
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
            'missing'         => 0,
        ]);
    }
}
