<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
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
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The write path of the second writing tool, against a real database, real file
 * mounts and the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler} (ADR-135).
 *
 * The mount recipe is the one
 * {@see FalToolsFileMountTest} and {@see BrowseFalFolderToolFileMountTest}
 * established: the storage ROW is inserted directly so the ResourceStorage
 * OBJECT is first built while the editor is logged in inside a backend request
 * — only then does the core StoragePermissionsAspect attach the user's file
 * mounts, and only then do {@see \Netresearch\NrLlm\Service\Tool\FalStorageGate}
 * (which asks for `read`) and core's `FileMetadataPermissionsAspect` (which asks
 * for `editMeta`, i.e. a WRITABLE mount) decide anything.
 *
 * Argument validation is unit-tested; everything here needs that permission
 * surface, the DataHandler's `errorLog`, and the read-back.
 */
#[CoversClass(SetFileAlternativeTextTool::class)]
final class SetFileAlternativeTextToolFileMountTest extends AbstractFunctionalTestCase
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

    /** A uid no file carries. */
    private const FILE_MISSING = 987654;

    private const METADATA_IN_MOUNT = 100;

    private const METADATA_OUTSIDE_MOUNT = 101;

    private const NEUTRAL_DENIAL = 'Asset not found or not permitted.';

    private ConnectionPool $connectionPool;

    private SetFileAlternativeTextTool $tool;

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

        $this->indexMetadata(self::METADATA_IN_MOUNT, self::FILE_IN_MOUNT, 'Old alt');
        $this->indexMetadata(self::METADATA_OUTSIDE_MOUNT, self::FILE_OUTSIDE_MOUNT, 'Secret alt');

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // tool refuses to write without it (ADR-135) — so the happy path has to
        // establish it, exactly as a backend request does.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('set_file_alternative_text');
        // From the registry, not `new`: the storage gate only enforces mounts
        // with the DI-wired StorageRepository behind it.
        self::assertInstanceOf(SetFileAlternativeTextTool::class, $tool);
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
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'A photo of the manual'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('manual.txt', $result->content);
        self::assertSame('A photo of the manual', $this->storedAlternative(self::METADATA_IN_MOUNT));
        // sys_log names the acting user of the run, not the ambient one (ADR-083).
        self::assertSame([2], array_values(array_unique($this->sysLogUserIdsFor(self::METADATA_IN_MOUNT))));
    }

    /**
     * An empty alternative text is the correct value for a decorative image, so
     * the tool accepts it rather than forcing an editor to invent prose.
     */
    #[Test]
    public function anEmptyAlternativeTextMarksTheImageAsDecorative(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => ''],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('(empty)', $result->content);
        self::assertSame('', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    /**
     * The three ways this tool can say no about a file say it in the SAME words.
     * A model that could tell them apart could probe `sys_file` for the existence
     * of files outside its mounts.
     */
    #[Test]
    public function everyFileRefusalIsByteIdenticalAndNothingIsWrittenOrCreated(): void
    {
        $editor  = $this->loginEditorInBackendRequest();
        $context = ToolExecutionContext::fromBackendUser($editor);

        $outside      = $this->tool->execute(['uid' => self::FILE_OUTSIDE_MOUNT, 'alternative' => 'Hijacked'], $context);
        $missing      = $this->tool->execute(['uid' => self::FILE_MISSING, 'alternative' => 'Hijacked'], $context);
        $noMetadata   = $this->tool->execute(['uid' => self::FILE_WITHOUT_METADATA, 'alternative' => 'Hijacked'], $context);

        self::assertTrue($outside->isError);
        self::assertTrue($missing->isError);
        self::assertTrue($noMetadata->isError);
        self::assertSame(self::NEUTRAL_DENIAL, $outside->content);
        self::assertSame($outside->content, $missing->content);
        self::assertSame($outside->content, $noMetadata->content);

        // The file outside the mount kept its alternative text.
        self::assertSame('Secret alt', $this->storedAlternative(self::METADATA_OUTSIDE_MOUNT));
        // And the undescribed file did NOT gain a metadata record: refusing is
        // the decision, not creating one on the way (ADR-135).
        self::assertSame(0, $this->metadataRowCountFor(self::FILE_WITHOUT_METADATA));
    }

    /**
     * The storage gate asks for `read`, the DataHandler's own
     * `FileMetadataPermissionsAspect` asks for `editMeta` — a WRITABLE mount.
     * A read-only mount passes the first and must be stopped by the second, and
     * that refusal must surface as an error rather than as a reported success.
     */
    #[Test]
    public function aReadOnlyMountPassesTheStorageGateAndIsStoppedByTheDataHandler(): void
    {
        $this->connectionPool->getConnectionForTable('sys_filemounts')
            ->update('sys_filemounts', ['read_only' => 1], ['uid' => 1]);

        $editor = $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Not allowed'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertSame('Old alt', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    /**
     * The tool's storage gate is not the only barrier, and it is not the last
     * one: an editor with a perfectly reachable file but no `sys_file_metadata`
     * table grant is stopped by the DataHandler, and that refusal surfaces as an
     * error rather than as a reported success.
     */
    #[Test]
    public function anEditorWithoutTheTableGrantIsRefusedByTheDataHandler(): void
    {
        $editor = $this->loginEditorInBackendRequest();
        $editor->groupData['tables_modify'] = '';
        self::assertFalse($editor->check('tables_modify', 'sys_file_metadata'));

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Not allowed'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertSame('Old alt', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    /**
     * The DataHandler drops a field the user lacks the "exclude field" grant for
     * SILENTLY — no exception, no `errorLog` entry. Core ships
     * `sys_file_metadata.alternative` without the `exclude` flag, so this is an
     * installation's TCA override rather than the default; the mechanism is the
     * same one that already bites on `pages`, and without the read-back the tool
     * would report a write that never happened to the human who approved it.
     */
    #[Test]
    public function aSilentlyDroppedFieldIsReportedAsAnErrorRatherThanASuccess(): void
    {
        $this->makeTheAlternativeFieldAccessControlled();

        $editor = $this->loginEditorInBackendRequest();
        // Deliberately NOT granting sys_file_metadata:alternative.
        $editor->groupData['non_exclude_fields'] = '';

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Never stored'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('did not take', $result->content);
        self::assertSame('Old alt', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    #[Test]
    public function theExcludeFieldGrantMakesTheSameWriteSucceed(): void
    {
        $this->makeTheAlternativeFieldAccessControlled();

        $editor = $this->loginEditorInBackendRequest();
        $editor->groupData['non_exclude_fields'] = 'sys_file_metadata:alternative';

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Granted'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Granted', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    /**
     * ADR-136: the arguments on the approval card ARE the new value, so the
     * preview's job is the other column — what the write would replace.
     */
    #[Test]
    public function thePreviewShowsTheStoredValueNextToTheProposedOne(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $lines = $this->tool->previewCall(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'A photo of the manual'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertSame('File [10] "manual.txt" — alternative text (default language):', $lines[0]);
        self::assertSame('alternative: "Old alt" → "A photo of the manual"', $lines[1]);

        // A preview reads; it must not write.
        self::assertSame('Old alt', $this->storedAlternative(self::METADATA_IN_MOUNT));
        self::assertSame([], $this->sysLogUserIdsFor(self::METADATA_IN_MOUNT));
    }

    #[Test]
    public function thePreviewNamesAnUnchangedValueAsSuch(): void
    {
        $editor = $this->loginEditorInBackendRequest();

        $lines = $this->tool->previewCall(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Old alt'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        // An approver should not have to diff two identical strings by eye.
        self::assertSame('alternative: unchanged ("Old alt")', $lines[1]);
    }

    /**
     * The preview authorises against the run's EXPLICIT acting user, exactly
     * like the write — otherwise it would be a read-anything oracle wearing the
     * write tool's name.
     */
    #[Test]
    public function thePreviewRefusesEveryUnreachableFileWithTheSameNeutralString(): void
    {
        $editor  = $this->loginEditorInBackendRequest();
        $context = ToolExecutionContext::fromBackendUser($editor);

        $outside    = $this->tool->previewCall(['uid' => self::FILE_OUTSIDE_MOUNT, 'alternative' => 'Hijacked'], $context);
        $missing    = $this->tool->previewCall(['uid' => self::FILE_MISSING, 'alternative' => 'Hijacked'], $context);
        $noMetadata = $this->tool->previewCall(['uid' => self::FILE_WITHOUT_METADATA, 'alternative' => 'Hijacked'], $context);

        self::assertSame([self::NEUTRAL_DENIAL], $outside);
        self::assertSame($outside, $missing);
        self::assertSame($outside, $noMetadata);
    }

    /**
     * An admin is not mount-bound, but the storage allow-list still applies —
     * and the tool must behave identically for them otherwise.
     */
    #[Test]
    public function anAdminWritesTheSameWayOutsideAnyMount(): void
    {
        $admin = $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $result = $this->tool->execute(
            ['uid' => self::FILE_OUTSIDE_MOUNT, 'alternative' => 'Described by an admin'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Described by an admin', $this->storedAlternative(self::METADATA_OUTSIDE_MOUNT));
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $this->loginEditorInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE_IN_MOUNT, 'alternative' => 'Service account'],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame(self::NEUTRAL_DENIAL, $result->content);
        self::assertSame('Old alt', $this->storedAlternative(self::METADATA_IN_MOUNT));
    }

    /**
     * Put `sys_file_metadata.alternative` under "exclude field" control, the way
     * an installation's TCA override can.
     *
     * Core ships the column WITHOUT the flag, so the silent-drop path is not
     * reachable on the default TCA — the guard exists for the installations that
     * do set it, and this is how the test reaches it. Mutating `$GLOBALS['TCA']`
     * alone is not enough in TYPO3 v14: the DataHandler asks the COMPILED
     * schema (`$schema->getField()->supportsAccessControl()`), so the schema has
     * to be rebuilt from the changed array.
     */
    private function makeTheAlternativeFieldAccessControlled(): void
    {
        // Narrowed step by step: $GLOBALS is untyped and the analyser is right
        // to insist that a fixture proves what it assumes about the live TCA.
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $table = $tca['sys_file_metadata'] ?? null;
        self::assertIsArray($table);
        $columns = $table['columns'] ?? null;
        self::assertIsArray($columns);
        $column = $columns['alternative'] ?? null;
        self::assertIsArray($column);

        $column['exclude']            = true;
        $columns['alternative']       = $column;
        $table['columns']             = $columns;
        $tca['sys_file_metadata']     = $table;
        $GLOBALS['TCA']               = $tca;

        $this->getService(TcaSchemaFactory::class)->rebuild($tca);
    }

    /**
     * The editor as a real installation configures one that may describe files:
     * a writable file mount AND the `sys_file_metadata` table grant.
     *
     * Both are required, and neither substitutes for the other.
     * `FileMetadataPermissionsAspect::checkModifyAccessList()` can only ever
     * DENY — it never grants — so `tables_modify` still decides first, exactly
     * as it does for the File list module's metadata form.
     */
    private function loginEditorInBackendRequest(): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(2);
        $user->groupData['tables_modify'] = 'sys_file_metadata';
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

    private function indexMetadata(int $uid, int $fileUid, string $alternative): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid'              => $uid,
            'pid'              => 0,
            'file'             => $fileUid,
            'sys_language_uid' => 0,
            'alternative'      => $alternative,
            'title'            => 'Stored title',
        ]);
    }

    private function storedAlternative(int $metadataUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('alternative')
            ->from('sys_file_metadata')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('metadata record %d must exist', $metadataUid));
        $stored = $row['alternative'] ?? null;
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
