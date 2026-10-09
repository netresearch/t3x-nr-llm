<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The attach-to-record writer against the real DataHandler and a real storage,
 * on a fixture table shaped like EXT:news' `fal_media` (NEXT-199): a hidden
 * draft record, a file field that accepts images only and one that accepts
 * anything. What matters is what ends up in `sys_file_reference` AND in the
 * record's own counter, and that a draft stays hidden.
 */
#[CoversClass(AttachFileToRecordTool::class)]
final class AttachFileToRecordToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;
    use RegistersTheInterferingHookTrait;

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private const GALLERY = 'tx_writerfixture_gallery';

    private const COVER = 'tx_writerfixture_cover';

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private ConnectionPool $connectionPool;

    private AttachFileToRecordTool $tool;

    /** The hidden draft on the page everybody may edit. */
    private int $draftUid = 0;

    /** A record on a page the editor may only see. */
    private int $closedRecordUid = 0;

    /** A record in the second language. */
    private int $translatedUid = 0;

    private int $coverUid = 0;

    /** @var mixed The TCA as the test framework built it, put back by tearDown(). */
    private mixed $originalTca = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTca = $GLOBALS['TCA'];
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/outside.jpg', 'root image');
        file_put_contents($this->instancePath . '/fileadmin/docs/one.jpg', 'first');
        file_put_contents($this->instancePath . '/fileadmin/docs/two.jpg', 'second');
        file_put_contents($this->instancePath . '/fileadmin/docs/notes.txt', 'not an image');

        $storage = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $storage->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $storage->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/', 'read_only' => 0,
        ]);
        $storage->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
            'tables_modify' => self::GALLERY . ',' . self::COVER . ',sys_file_reference',
            'non_exclude_fields' => self::GALLERY . ':media,' . self::GALLERY . ':attachments,sys_file_reference:title,sys_file_reference:alternative,sys_file_reference:description',
            'db_mountpoints' => '1',
        ]);
        $storage->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $files = $this->connectionPool->getConnectionForTable('sys_file');
        foreach ([
            [1, '/docs/one.jpg', 'one.jpg', 'jpg', 'image/jpeg'],
            [2, '/docs/two.jpg', 'two.jpg', 'jpg', 'image/jpeg'],
            [3, '/docs/notes.txt', 'notes.txt', 'txt', 'text/plain'],
            [4, '/outside.jpg', 'outside.jpg', 'jpg', 'image/jpeg'],
        ] as [$uid, $identifier, $name, $extension, $mime]) {
            $files->insert('sys_file', [
                'uid' => $uid, 'storage' => 1, 'identifier' => $identifier, 'name' => $name,
                'extension' => $extension, 'mime_type' => $mime, 'size' => 8,
                'sha1' => str_repeat((string)$uid, 40),
            ]);
        }

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Folder', 'doktype' => 254,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 9, 'perms_group' => Permission::ALL,
            'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid' => 2, 'pid' => 0, 'title' => 'Closed folder', 'doktype' => 254,
            'perms_userid' => 99, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);

        $gallery = $this->connectionPool->getConnectionForTable(self::GALLERY);
        $gallery->insert(self::GALLERY, ['pid' => 1, 'title' => 'Draft', 'hidden' => 1, 'sys_language_uid' => 0]);

        $this->draftUid = (int)$gallery->lastInsertId();
        $gallery->insert(self::GALLERY, ['pid' => 2, 'title' => 'On a closed folder', 'hidden' => 1, 'sys_language_uid' => 0]);
        $this->closedRecordUid = (int)$gallery->lastInsertId();
        $gallery->insert(self::GALLERY, ['pid' => 1, 'title' => 'Translation', 'hidden' => 1, 'sys_language_uid' => 1]);
        $this->translatedUid = (int)$gallery->lastInsertId();

        $cover = $this->connectionPool->getConnectionForTable(self::COVER);
        $cover->insert(self::COVER, ['pid' => 1, 'title' => 'A cover record']);

        $this->coverUid = (int)$cover->lastInsertId();

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // write guard refuses without it rather than crashing halfway through.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $this->tool = new AttachFileToRecordTool($this->connectionPool, $gate, new TableReadAccessService(), new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * Lay a change over the TCA for the rest of this test.
     *
     * @param array<string, mixed> $patch
     */
    private function patchTca(array $patch): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $GLOBALS['TCA'] = array_replace_recursive($tca, $patch);
    }

    /**
     * The preview as the run's acting user reads it, in that user's language
     * (ADR-213).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewIn(string $language, array $arguments): array
    {
        $admin               = $this->actor(1);
        $admin->user['lang'] = $language;

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));
    }

    private function actor(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        // The core StoragePermissionsAspect only attaches mounts and
        // permissions when the storage object is built inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $user;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function attach(array $arguments, int $userUid = 1): ToolResult
    {
        return $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->actor($userUid)));
    }

    /**
     * @return list<array{uid: int, uid_local: int, sorting_foreign: int}>
     */
    private function references(string $table, int $record, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'uid_local', 'sorting_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($record, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'uid'             => (int)$row['uid'],
                'uid_local'       => (int)$row['uid_local'],
                'sorting_foreign' => (int)$row['sorting_foreign'],
            ],
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    #[Test]
    public function aHiddenDraftGetsItsImageAndStaysHidden(): void
    {
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(
            [['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1]],
            $this->references(self::GALLERY, $this->draftUid, 'media'),
        );
        $draft = $this->row(self::GALLERY, $this->draftUid);
        self::assertSame(1, (int)$draft['media'], 'The record must count the reference it now holds.');
        self::assertSame(1, (int)$draft['hidden'], 'Attaching a file must not publish the draft.');
    }

    #[Test]
    public function aSecondFileIsAppendedAfterTheFirst(): void
    {
        self::assertFalse($this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media'])->isError);
        $second = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 2, 'field' => 'media']);

        self::assertFalse($second->isError, $second->content);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1],
                ['uid' => 2, 'uid_local' => 2, 'sorting_foreign' => 2],
            ],
            $this->references(self::GALLERY, $this->draftUid, 'media'),
        );
        self::assertSame(2, (int)$this->row(self::GALLERY, $this->draftUid)['media']);
    }

    #[Test]
    public function theTextsLandOnTheReference(): void
    {
        $result = $this->attach([
            'table'       => self::GALLERY,
            'record'      => $this->draftUid,
            'file'        => 1,
            'field'       => 'media',
            'title'       => 'A caption',
            'alternative' => 'A description of the image',
            'description' => 'A longer text',
        ]);

        self::assertFalse($result->isError, $result->content);
        $reference = $this->row('sys_file_reference', 1);
        self::assertSame('A caption', $reference['title']);
        self::assertSame('A description of the image', $reference['alternative']);
        self::assertSame('A longer text', $reference['description']);
        self::assertSame(self::GALLERY, $reference['tablenames']);
        self::assertSame('media', $reference['fieldname']);
        self::assertSame($this->draftUid, (int)$reference['uid_foreign']);
    }

    /**
     * An asked empty text is an explicit override, and NULL is not one: a hook
     * that drops it leaves NULL, which did not take (ADR-214). The other
     * direction, '' stored as '', took.
     */
    #[Test]
    public function anEmptyTextTookOnlyWhenItIsStoredAsEmpty(): void
    {
        $arguments = ['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media', 'alternative' => ''];

        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        $dropped = $this->attach($arguments);
        self::assertTrue($dropped->isError);
        self::assertStringContainsString('"alternative" did not take', $dropped->content);

        $this->unregisterInterferingHook();
        $taken = $this->attach($arguments);
        self::assertFalse($taken->isError, $taken->content);
    }

    /**
     * A title is 255 characters in the TCA, and core cuts a longer one without
     * a word: refused before anything is written, while 255 is taken.
     */
    #[Test]
    public function aTitleIsTakenUpToItsColumnsLimitAndRefusedBeyondIt(): void
    {
        $arguments = ['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media'];

        $refused = $this->attach([...$arguments, 'title' => str_repeat('t', 256)]);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('"title" is longer than 255 characters', $refused->content);

        $taken = $this->attach([...$arguments, 'title' => str_repeat('t', 255)]);
        self::assertFalse($taken->isError, $taken->content);
    }

    /**
     * Both directions of the `allowed` rule: `media` takes images only, and the
     * same text file is fine for `attachments`.
     */
    #[Test]
    public function theAllowedListOfTheFieldDecidesWhichFileIsRefused(): void
    {
        $refused = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 3, 'field' => 'media']);

        self::assertTrue($refused->isError);
        self::assertStringContainsString('does not accept a .txt file', $refused->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));

        $accepted = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 3, 'field' => 'attachments']);

        self::assertFalse($accepted->isError, $accepted->content);
        self::assertCount(1, $this->references(self::GALLERY, $this->draftUid, 'attachments'));
    }

    #[Test]
    public function aColumnThatIsNoFileFieldIsRefused(): void
    {
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'title']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not a file field of ' . self::GALLERY, $result->content);
        self::assertStringContainsString('media, attachments', $result->content);
    }

    #[Test]
    public function anOmittedFieldIsRefusedWhereSeveralExistAndInferredWhereOneDoes(): void
    {
        $ambiguous = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1]);

        self::assertTrue($ambiguous->isError);
        self::assertStringContainsString('Name the one you mean', $ambiguous->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));

        $inferred = $this->attach(['table' => self::COVER, 'record' => $this->coverUid, 'file' => 1]);

        self::assertFalse($inferred->isError, $inferred->content);
        self::assertCount(1, $this->references(self::COVER, $this->coverUid, 'cover'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTables(): iterable
    {
        yield 'pages has set_page_social_image' => ['pages', 'set_page_social_image'];
        yield 'tt_content has its own tool' => ['tt_content', 'attach_file_to_content_element'];
        yield 'a system table' => ['sys_file_metadata', 'system or sensitive'];
        yield 'a sensitive table outside the sys_ namespace' => ['fe_users', 'system or sensitive'];
        yield 'a table nobody declares' => ['tx_nothing_here', 'not a table this installation declares'];
    }

    #[Test]
    #[DataProvider('refusedTables')]
    public function tablesWithANarrowerToolOrNoBusinessHereAreRefused(string $table, string $expectedFragment): void
    {
        $result = $this->attach(['table' => $table, 'record' => 1, 'file' => 1, 'field' => 'media']);

        self::assertTrue($result->isError);
        self::assertStringContainsString($expectedFragment, $result->content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTcaFlags(): iterable
    {
        yield 'adminOnly' => ['adminOnly'];
        yield 'hideTable' => ['hideTable'];
        yield 'readOnly' => ['readOnly'];
    }

    #[Test]
    #[DataProvider('refusedTcaFlags')]
    public function aTableDeclaredAdminOnlyHiddenOrReadOnlyIsRefused(string $flag): void
    {
        $this->patchTca([self::COVER => ['ctrl' => [$flag => true]]]);
        $result = $this->attach(['table' => self::COVER, 'record' => $this->coverUid, 'file' => 1]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('is declared ' . $flag . ' in its TCA', $result->content);
        self::assertSame([], $this->references(self::COVER, $this->coverUid, 'cover'));
    }

    #[Test]
    public function aUserWithoutTablesModifyOnTheTableIsRefused(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['tables_modify' => self::COVER . ',sys_file_reference'],
            ['uid' => 9],
        );

        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media'], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('no tables_modify grant', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
    }

    #[Test]
    public function aDisallowedExtensionIsRefusedWhereTheFieldAllowsEverythingElse(): void
    {
        $this->patchTca([self::GALLERY => ['columns' => ['attachments' => ['config' => ['disallowed' => 'txt']]]]]);
        $refused  = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 3, 'field' => 'attachments']);
        $accepted = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'attachments']);

        self::assertTrue($refused->isError);
        self::assertStringContainsString('does not accept a .txt file', $refused->content);
        self::assertFalse($accepted->isError, $accepted->content);
        self::assertCount(1, $this->references(self::GALLERY, $this->draftUid, 'attachments'));
    }

    /**
     * The backend form narrows a field per record type through
     * `columnsOverrides`; the tool must not accept what that form rejects.
     */
    #[Test]
    public function aTypeOverrideOfTheAllowedListNarrowsTheField(): void
    {
        $this->patchTca([self::GALLERY => ['types' => ['1' => ['columnsOverrides' => ['media' => ['config' => ['allowed' => 'png']]]]]]]);
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not accept a .jpg file. It accepts: png.', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
    }

    /**
     * The reference that was already there survives the rollback of the one
     * that failed, and the counter goes back to what it was.
     */
    #[Test]
    public function aFailedSecondAttachmentLeavesTheFirstOneIntact(): void
    {
        self::assertFalse($this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media'])->isError);
        $this->connectionPool->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['non_exclude_fields' => self::GALLERY . ':media'],
            ['uid' => 9],
        );

        $result = $this->attach([
            'table'       => self::GALLERY,
            'record'      => $this->draftUid,
            'file'        => 2,
            'field'       => 'media',
            'alternative' => 'An alt text the editor may not set',
        ], userUid: 2);

        self::assertTrue($result->isError, $result->content);
        self::assertSame(
            [['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1]],
            $this->references(self::GALLERY, $this->draftUid, 'media'),
        );
        self::assertSame(1, (int)$this->row(self::GALLERY, $this->draftUid)['media']);
    }

    #[Test]
    public function aFileOutsideTheActingUsersMountsIsRefused(): void
    {
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 4, 'field' => 'media'], userUid: 2);

        self::assertTrue($result->isError);
        self::assertSame('Record or file not found, or not permitted.', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
    }

    /**
     * A record that does not exist, one on a page the user may only see, and a
     * file the user may not reach are refused in the same words.
     */
    #[Test]
    public function absentAndUnreachableRecordsAndFilesRefuseIdentically(): void
    {
        $absent      = $this->attach(['table' => self::GALLERY, 'record' => 999, 'file' => 1, 'field' => 'media'], userUid: 2);
        $closed      = $this->attach(['table' => self::GALLERY, 'record' => $this->closedRecordUid, 'file' => 1, 'field' => 'media'], userUid: 2);
        $unreachable = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 4, 'field' => 'media'], userUid: 2);

        self::assertTrue($absent->isError);
        self::assertSame($absent->content, $closed->content);
        self::assertSame($absent->content, $unreachable->content);
        self::assertSame([], $this->references(self::GALLERY, $this->closedRecordUid, 'media'));
    }

    #[Test]
    public function aRecordInAnotherLanguageIsRefused(): void
    {
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->translatedUid, 'file' => 1, 'field' => 'media']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('default language only', $result->content);
    }

    #[Test]
    public function anUnknownArgumentRefusesTheWholeCall(): void
    {
        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media', 'hidden' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('"hidden" is not an argument of this tool', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
    }

    #[Test]
    public function aFieldWithoutTheExcludeFieldGrantIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['non_exclude_fields' => 'sys_file_reference:title'],
            ['uid' => 9],
        );

        $result = $this->attach(['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media'], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('no field-level ("exclude field") grant for ' . self::GALLERY . ':media', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
    }

    /**
     * `alternative` is an exclude field on `sys_file_reference`, so the
     * DataHandler drops it for a user without the grant — silently. Reported
     * as a failure, and the half-written reference is removed rather than left
     * behind, with the record's counter back at what it was.
     */
    #[Test]
    public function aSilentlyDroppedAlternativeTextIsReportedAndTheReferenceIsRemoved(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['non_exclude_fields' => self::GALLERY . ':media'],
            ['uid' => 9],
        );

        $result = $this->attach([
            'table'       => self::GALLERY,
            'record'      => $this->draftUid,
            'file'        => 1,
            'field'       => 'media',
            'alternative' => 'An alt text the editor may not set',
        ], userUid: 2);

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"alternative" did not take', $result->content);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'));
        self::assertSame(0, (int)$this->row(self::GALLERY, $this->draftUid)['media']);
    }

    #[Test]
    public function anEditorWithEveryGrantAttachesTheFileWithItsAlternativeText(): void
    {
        $result = $this->attach([
            'table'       => self::GALLERY,
            'record'      => $this->draftUid,
            'file'        => 1,
            'field'       => 'media',
            'alternative' => 'An alt text the editor may set',
        ], userUid: 2);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('An alt text the editor may set', $this->row('sys_file_reference', 1)['alternative']);
        self::assertSame(1, (int)$this->row(self::GALLERY, $this->draftUid)['hidden']);
    }

    #[Test]
    public function thePreviewNamesTheRecordTheFieldAndTheFile(): void
    {
        $arguments = ['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 1, 'field' => 'media', 'alternative' => 'A description'];

        self::assertSame([
            'Add file to record',
            sprintf('Record: %s “Draft”', $this->tcaLabelIn('en', self::GALLERY, 'ctrl', 'title')),
            'Location: on page “Folder”',
            'Language: default language',
            'Field: ' . $this->tcaLabelIn('en', self::GALLERY, 'columns', 'media', 'label'),
            'Files in this field: currently 0, afterwards 1; the new file comes last',
            'New file: “one.jpg”, stored at “/docs/one.jpg”',
            'Alternative text: “A description”',
            sprintf('Technical details: table %s, UID %d, page UID 1, fields media, file UID 1', self::GALLERY, $this->draftUid),
        ], $this->previewIn('en', $arguments));
        $german = $this->previewIn('de', $arguments);
        self::assertSame([
            'Datei zum Datensatz hinzufügen',
            sprintf('Datensatz: %s „Draft“', $this->tcaLabelIn('de', self::GALLERY, 'ctrl', 'title')),
            'Ort: auf der Seite „Folder“',
            'Sprache: Standardsprache',
            'Feld: ' . $this->tcaLabelIn('de', self::GALLERY, 'columns', 'media', 'label'),
            'Dateien in diesem Feld: aktuell 0, danach 1; die neue Datei steht an letzter Stelle',
            'Neue Datei: „one.jpg“, gespeichert unter „/docs/one.jpg“',
            'Alternativtext: „A description“',
            sprintf('Technische Details: Tabelle %s, UID %d, Seite UID 1, Felder media, Datei UID 1', self::GALLERY, $this->draftUid),
        ], $german);
        self::assertGermanEditorLines($german);
        self::assertSame([], $this->references(self::GALLERY, $this->draftUid, 'media'), 'A preview must not write.');
    }

    #[Test]
    public function aViewerWhoMayNotReachTheFileGetsNoPreview(): void
    {
        $arguments = ['table' => self::GALLERY, 'record' => $this->draftUid, 'file' => 4, 'field' => 'media'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->actor(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->actor(2)));
    }
}
