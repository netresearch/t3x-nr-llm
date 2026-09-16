<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The write path of the eighth writing tool, against a real database, real
 * file mounts and the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 *
 * The mount recipe is the one {@see SetFileAlternativeTextToolFileMountTest}
 * and its siblings established: the storage ROW is inserted directly so the
 * ResourceStorage OBJECT is first built while the editor is logged in inside a
 * backend request — only then does the core StoragePermissionsAspect attach the
 * user's file mounts, and only then do
 * {@see \Netresearch\NrLlm\Service\Tool\FalStorageGate} (which asks for `read`)
 * and core's `FileMetadataPermissionsAspect` (which asks for `editMeta`) decide
 * anything.
 *
 * Argument validation is unit-tested. What needs a real database is everything
 * this tool does that the one-field writer before it could not get wrong: an
 * omitted field, a field-level grant held for one column and not the other, and
 * a read-back that has to speak per field.
 */
#[CoversClass(UpdateFalAssetMetaTool::class)]
final class UpdateFalAssetMetaToolFileMountTest extends AbstractFunctionalTestCase
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

    /** In the editor's `/docs/` mount, with a metadata record. */
    private const FILE_IN_MOUNT = 10;

    /** In the storage root, outside the editor's mount, with a metadata record. */
    private const FILE_OUTSIDE_MOUNT = 11;

    /** In the mount, but never indexed into `sys_file_metadata`. */
    private const FILE_WITHOUT_METADATA = 12;

    private const METADATA_IN_MOUNT = 100;

    private const METADATA_OUTSIDE_MOUNT = 101;

    /**
     * A draft-workspace version of {@see self::METADATA_IN_MOUNT}, deliberately
     * with the LOWER uid — an `ORDER BY uid ASC` alone would pick it.
     */
    private const METADATA_DRAFT_IN_MOUNT = 99;

    private const NEUTRAL_DENIAL = 'Asset not found or not permitted.';

    private const STORED_TITLE = 'Stored title';

    private const STORED_DESCRIPTION = 'Stored description';

    private ConnectionPool $connectionPool;

    private UpdateFalAssetMetaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/top-secret.txt', 'root file');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');
        file_put_contents($this->instancePath . '/fileadmin/docs/undescribed.txt', 'No metadata');

        $storageConnection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        self::assertInstanceOf(Connection::class, $storageConnection);
        // Row only — the ResourceStorage OBJECT must not exist before login.
        $storageConnection->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $storageConnection->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/', 'read_only' => 0,
        ]);
        $storageConnection->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Doc readers', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups.
        $storageConnection->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $fileConnection = $this->connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $fileConnection);
        $this->indexFile($fileConnection, self::FILE_IN_MOUNT, '/docs/manual.txt', 'manual.txt');
        $this->indexFile($fileConnection, self::FILE_OUTSIDE_MOUNT, '/top-secret.txt', 'top-secret.txt');
        $this->indexFile($fileConnection, self::FILE_WITHOUT_METADATA, '/docs/undescribed.txt', 'undescribed.txt');

        $this->indexMetadata(self::METADATA_IN_MOUNT, self::FILE_IN_MOUNT);
        $this->indexMetadata(self::METADATA_OUTSIDE_MOUNT, self::FILE_OUTSIDE_MOUNT);

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite and the
        // tool refuses to write without it, so the happy path has to establish
        // it exactly as a backend request does.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('update_fal_asset_meta');
        // From the registry, not `new`: the storage gate only enforces mounts
        // with the DI-wired StorageRepository behind it.
        self::assertInstanceOf(UpdateFalAssetMetaTool::class, $tool);
        $this->tool = $tool;
    }

    protected function tearDown(): void
    {
        // The faked backend request must not leak into later tests.
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorDescribesAFileInsideTheirMount(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'title' => 'The manual', 'description' => 'How the thing works.'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('manual.txt', $result->content);
        self::assertSame('The manual', $this->stored(self::METADATA_IN_MOUNT, 'title'));
        self::assertSame('How the thing works.', $this->stored(self::METADATA_IN_MOUNT, 'description'));
        // sys_log names the acting user of the run, not the ambient one (ADR-083).
        self::assertSame([2], array_values(array_unique($this->sysLogUserIdsFor(self::METADATA_IN_MOUNT))));
    }

    /**
     * The decision that keeps this tool from destroying work: a field the call
     * leaves out is absent from the datamap, not written as empty. Setting a
     * title must never erase a description somebody wrote by hand.
     */
    #[Test]
    public function anOmittedFieldKeepsItsStoredValue(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'title' => 'Only the title'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Only the title', $this->stored(self::METADATA_IN_MOUNT, 'title'));
        self::assertSame(self::STORED_DESCRIPTION, $this->stored(self::METADATA_IN_MOUNT, 'description'));
        // The success line reports what it changed and nothing else.
        self::assertStringNotContainsString('description', $result->content);
    }

    /**
     * An empty string is a value and clears the field — which is exactly why it
     * may not be what an omitted argument means.
     */
    #[Test]
    public function anEmptyStringClearsTheFieldItNames(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'description' => ''],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('', $this->stored(self::METADATA_IN_MOUNT, 'description'));
        self::assertSame(self::STORED_TITLE, $this->stored(self::METADATA_IN_MOUNT, 'title'));
    }

    /**
     * Core ships `sys_file_metadata.title` with the `exclude` flag and
     * `description` without one, so an editor granted the second and not the
     * first is an ordinary installation rather than a contrived one. The
     * DataHandler would drop `title` in silence and write `description`; the
     * tool refuses the whole call first, and the stored record proves nothing
     * moved.
     */
    #[Test]
    public function anEditorWithoutTheTitleGrantChangesNothingAtAll(): void
    {
        $editor                                  = $this->loginEditorInBackendRequest();
        $editor->groupData['non_exclude_fields'] = 'sys_file_metadata:description';

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'title' => 'New title', 'description' => 'New description'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_file_metadata:title', $result->content);
        self::assertSame(self::STORED_TITLE, $this->stored(self::METADATA_IN_MOUNT, 'title'));
        self::assertSame(self::STORED_DESCRIPTION, $this->stored(self::METADATA_IN_MOUNT, 'description'));
    }

    #[Test]
    public function aFileOutsideTheEditorsMountIsDeniedInTheNeutralWords(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_OUTSIDE_MOUNT, 'title' => 'Mine now'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame(self::NEUTRAL_DENIAL, $result->content);
        self::assertSame(self::STORED_TITLE, $this->stored(self::METADATA_OUTSIDE_MOUNT, 'title'));
    }

    /**
     * A file with no metadata record is refused in the same words, and the tool
     * does NOT bring one into being: a record it invented is a record nobody
     * reviewed.
     */
    #[Test]
    public function aFileWithoutAMetadataRecordIsRefusedRatherThanIndexed(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_WITHOUT_METADATA, 'title' => 'Invented'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame(self::NEUTRAL_DENIAL, $result->content);
        self::assertSame(0, $this->metadataRowCountFor(self::FILE_WITHOUT_METADATA));
    }

    /**
     * A file is looked up by `file`, not by uid, so a workspace version of the
     * metadata record is a SECOND row with the same `file` and the same
     * `sys_language_uid = 0`. The tool must write the LIVE one.
     *
     * Unpinned, the write can land on a stranger's unpublished draft, leave the
     * live values untouched and still report success — the read-back re-reads
     * the uid it wrote, so it would confirm the wrong row instead of catching
     * it. The draft here carries the LOWER uid on purpose: an ordered but
     * unrestricted query would pick it.
     */
    #[Test]
    public function aWorkspaceVersionOfTheMetadataRecordIsNeverTheRowThatIsWritten(): void
    {
        $this->indexWorkspaceVersionOf(self::METADATA_DRAFT_IN_MOUNT, self::METADATA_IN_MOUNT, self::FILE_IN_MOUNT);
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'title' => 'Live title'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Live title', $this->stored(self::METADATA_IN_MOUNT, 'title'));
        self::assertSame('Draft title', $this->stored(self::METADATA_DRAFT_IN_MOUNT, 'title'));
    }

    /**
     * The approval card shows the fields the call sets, with their current
     * values — and says nothing about the field it leaves alone, so an approver
     * cannot read its absence as a clearing.
     */
    #[Test]
    public function theApprovalCardShowsOnlyTheFieldsTheCallWouldChange(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $lines = $this->tool->previewCall(
            ['uid' => self::FILE_IN_MOUNT, 'title' => 'A better title'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        $card = implode("\n", $lines);
        self::assertStringContainsString('manual.txt', $card);
        self::assertStringContainsString(self::STORED_TITLE, $card, 'the approver reads the value being replaced');
        self::assertStringContainsString('A better title', $card);
        self::assertStringNotContainsString('description', $card);
    }

    private function loginEditorInBackendRequest(): BackendUserAuthentication
    {
        $user                             = $this->setUpBackendUser(2);
        $user->groupData['tables_modify'] = 'sys_file_metadata';
        // Core marks `title` as an exclude field, so the editor needs the
        // field-level grant for it as a matter of course; the test that asks
        // what happens WITHOUT it narrows this afterwards.
        $user->groupData['non_exclude_fields'] = 'sys_file_metadata:title,sys_file_metadata:description';
        // The core StoragePermissionsAspect only attaches mounts/permissions
        // when the storage object is created inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $user;
    }

    private function indexFile(Connection $connection, int $uid, string $identifier, string $name): void
    {
        // identifier_hash / folder_hash use TYPO3's own sha1 identifier-hash
        // algorithm so getFile() resolves the index row exactly as core does.
        // This is a test fixture, not a security context (SonarCloud's
        // "weak hashing" flag here is a false positive).
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
            // sys_file_metadata takes its record type from the file's type
            // (`ctrl.type = file:type`); 1 is FileType::TEXT and the only type
            // the metadata TCA defines.
            'type'            => 1,
            'missing'         => 0,
        ]);
    }

    private function indexMetadata(int $uid, int $fileUid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid'              => $uid,
            'pid'              => 0,
            'file'             => $fileUid,
            'sys_language_uid' => 0,
            'title'            => self::STORED_TITLE,
            'description'      => self::STORED_DESCRIPTION,
            'alternative'      => 'Stored alt',
        ]);
    }

    /**
     * A draft-workspace version of an existing metadata record, as the
     * DataHandler creates one when an editor saves file metadata in a workspace:
     * same `file`, same `sys_language_uid`, pointing back at the live row.
     */
    private function indexWorkspaceVersionOf(int $uid, int $liveUid, int $fileUid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid'              => $uid,
            'pid'              => 0,
            'file'             => $fileUid,
            'sys_language_uid' => 0,
            'title'            => 'Draft title',
            'description'      => 'Draft description',
            't3ver_wsid'       => 1,
            't3ver_oid'        => $liveUid,
        ]);
    }

    private function stored(int $metadataUid, string $field): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select($field)
            ->from('sys_file_metadata')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('metadata record %d must exist', $metadataUid));
        $stored = $row[$field] ?? null;
        self::assertIsString($stored);

        return $stored;
    }

    private function metadataRowCountFor(int $fileUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_file_metadata')
            ->where($queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The `sys_log` user ids the DataHandler recorded for a metadata record.
     *
     * @return list<int>
     */
    private function sysLogUserIdsFor(int $metadataUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('sys_file_metadata')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
