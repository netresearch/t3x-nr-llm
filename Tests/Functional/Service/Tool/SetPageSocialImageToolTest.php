<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\DropsThePageImageFieldsHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\DropsTheReferenceDeleteCommandHook;
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
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The social-image writer against the real DataHandler, a real storage and the
 * `pages` columns EXT:seo declares (ADR-195).
 *
 * Functional rather than unit because what matters is what ends up in
 * `sys_file_reference` AND in the page's own counter, and what happens to the
 * reference that was there before. {@see SetPageSocialImageToolWithoutSeoTest}
 * holds the other direction: without EXT:seo the fields do not exist and the
 * call is refused.
 */
#[CoversClass(SetPageSocialImageTool::class)]
final class SetPageSocialImageToolTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'seo',
    ];

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private const NOT_PERMITTED = 'Page or file not found, or not permitted.';

    /** A page the editors' group may edit. */
    private const PAGE_OPEN = 1;

    /** A page only the admin may edit; everybody else may merely see it. */
    private const PAGE_CLOSED = 2;

    /** The translation of the open page. */
    private const PAGE_TRANSLATED = 3;

    private const PAGE_MISSING = 999;

    private const FILE_ONE = 1;

    private const FILE_TWO = 2;

    private const FILE_TEXT = 3;

    private const FILE_OUTSIDE_MOUNT = 4;

    private const FILE_MISSING = 999;

    private const EDITOR_GROUP = 9;

    private ConnectionPool $connectionPool;

    private SetPageSocialImageTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
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
        // `og_image` and `twitter_image` are exclude fields, so the editor
        // needs the field-level grant besides the table grants; the open page
        // sits inside the DB mount, the closed one too, so its denial comes
        // from the permission bits and not from an unreachable mount.
        $storage->insert('be_groups', [
            'uid' => self::EDITOR_GROUP, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
            'tables_modify' => 'pages,sys_file_reference',
            'non_exclude_fields' => 'pages:og_image,pages:twitter_image',
            'db_mountpoints' => '1,2',
        ]);
        // options = 3: inherit DB and file mounts from the groups.
        $storage->update('be_users', ['usergroup' => (string)self::EDITOR_GROUP, 'options' => 3], ['uid' => 2]);

        $files = $this->connectionPool->getConnectionForTable('sys_file');
        foreach ([
            [self::FILE_ONE, '/docs/one.jpg', 'one.jpg', 'jpg', 'image/jpeg'],
            [self::FILE_TWO, '/docs/two.jpg', 'two.jpg', 'jpg', 'image/jpeg'],
            [self::FILE_TEXT, '/docs/notes.txt', 'notes.txt', 'txt', 'text/plain'],
            [self::FILE_OUTSIDE_MOUNT, '/outside.jpg', 'outside.jpg', 'jpg', 'image/jpeg'],
        ] as [$uid, $identifier, $name, $extension, $mime]) {
            $files->insert('sys_file', [
                'uid' => $uid, 'storage' => 1, 'identifier' => $identifier, 'name' => $name,
                'extension' => $extension, 'mime_type' => $mime, 'size' => 8,
                'sha1' => str_repeat((string)$uid, 40),
            ]);
        }

        // The editors hold PAGE_EDIT on the open page and NOT CONTENT_EDIT: the
        // right the tool authorises against, and the one the DataHandler asks
        // for a reference row written beside its page (`hasPermissionToInsert()`,
        // `hasPermissionToUpdate()`, `deleteRecord()`). A reference row written
        // WITHOUT the page in the datamap needs CONTENT_EDIT instead, so any
        // step the tool performs that way fails here rather than passing on a
        // grant an editor of page properties does not necessarily hold.
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN, 'pid' => 0, 'title' => 'Open page', 'doktype' => 1, 'slug' => '/open',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => self::EDITOR_GROUP, 'perms_group' => Permission::PAGE_SHOW | Permission::PAGE_EDIT,
            'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $pages->insert('pages', [
            'uid' => self::PAGE_CLOSED, 'pid' => 0, 'title' => 'Closed page', 'doktype' => 1, 'slug' => '/closed',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $pages->insert('pages', [
            'uid' => self::PAGE_TRANSLATED, 'pid' => 0, 'title' => 'Offene Seite', 'doktype' => 1, 'slug' => '/open',
            'sys_language_uid' => 1, 'l10n_parent' => self::PAGE_OPEN,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => self::EDITOR_GROUP, 'perms_group' => Permission::ALL,
            'perms_everybody' => Permission::ALL,
        ]);

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // write guard refuses without it rather than crashing halfway through.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $this->tool = new SetPageSocialImageTool($this->connectionPool, $gate);
    }

    protected function tearDown(): void
    {
        $this->stopDroppingThePageImageFields();
        $this->stopDroppingTheReferenceDeleteCommand();
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * Make the DataHandler run that creates the reference drop the page's
     * image fields in silence — the state the read-back exists to catch; see
     * the hook.
     */
    private function dropThePageImageFields(): void
    {
        $this->registerDataHandlerHook('processDatamapClass', DropsThePageImageFieldsHook::class);
    }

    private function stopDroppingThePageImageFields(): void
    {
        $this->unregisterDataHandlerHook('processDatamapClass', DropsThePageImageFieldsHook::class);
    }

    /**
     * Make the DataHandler run that creates the reference skip the delete of
     * the first replaced one in silence — the state the check after the
     * cmdmap exists to catch; see the hook.
     */
    private function dropTheReferenceDeleteCommand(): void
    {
        $this->registerDataHandlerHook('processCmdmapClass', DropsTheReferenceDeleteCommandHook::class);
    }

    private function stopDroppingTheReferenceDeleteCommand(): void
    {
        $this->unregisterDataHandlerHook('processCmdmapClass', DropsTheReferenceDeleteCommandHook::class);
    }

    private function registerDataHandlerHook(string $list, string $className): void
    {
        $hooks   = $this->dataHandlerHooks($list);
        $hooks[] = $className;
        $this->storeDataHandlerHooks($list, $hooks);
    }

    private function unregisterDataHandlerHook(string $list, string $className): void
    {
        $this->storeDataHandlerHooks($list, array_filter(
            $this->dataHandlerHooks($list),
            static fn(mixed $registeredClassName): bool => $registeredClassName !== $className,
        ));
    }

    /**
     * One DataHandler hook list, narrowed step by step — `$GLOBALS` is `mixed`.
     *
     * @return array<array-key, mixed>
     */
    private function dataHandlerHooks(string $list): array
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $options  = is_array($confVars) ? ($confVars['SC_OPTIONS'] ?? []) : [];
        $tcemain  = is_array($options) ? ($options['t3lib/class.t3lib_tcemain.php'] ?? []) : [];
        $hooks    = is_array($tcemain) ? ($tcemain[$list] ?? []) : [];

        return is_array($hooks) ? $hooks : [];
    }

    /**
     * @param array<array-key, mixed> $hooks
     */
    private function storeDataHandlerHooks(string $list, array $hooks): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $options = $confVars['SC_OPTIONS'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $tcemain = $options['t3lib/class.t3lib_tcemain.php'] ?? [];
        if (!is_array($tcemain)) {
            $tcemain = [];
        }

        $tcemain[$list]                           = $hooks;
        $options['t3lib/class.t3lib_tcemain.php'] = $tcemain;
        $confVars['SC_OPTIONS']                   = $options;
        $GLOBALS['TYPO3_CONF_VARS']               = $confVars;
    }

    /**
     * A reference row written past the tool, the way FormEngine or an earlier
     * run leaves one: `sys_file_reference` and, when asked, the page's counter.
     *
     * @param array<string, int> $fields column overrides for the reference row
     */
    private function seedReference(int $uid, int $fileUid, string $field, array $fields = []): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', $fields + [
            'uid' => $uid, 'pid' => self::PAGE_OPEN, 'uid_local' => $fileUid, 'uid_foreign' => self::PAGE_OPEN,
            'tablenames' => 'pages', 'fieldname' => $field, 'sorting_foreign' => $uid,
            'sys_language_uid' => 0, 'l10n_parent' => 0, 'deleted' => 0,
        ]);
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
    private function set(array $arguments, int $userUid = 1): ToolResult
    {
        return $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->actor($userUid)));
    }

    /**
     * Every reference row on the page's field, deleted ones included, so a test
     * can tell a soft-deleted reference from one that never existed.
     *
     * @return list<array{uid: int, uid_local: int, deleted: int}>
     */
    private function references(string $field, int $pageUid = self::PAGE_OPEN): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'uid_local', 'deleted')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'uid'       => (int)$row['uid'],
                'uid_local' => (int)$row['uid_local'],
                'deleted'   => (int)$row['deleted'],
            ],
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    /**
     * Give `pages.og_image` the TCA shape an installation's override can, the
     * way {@see SetFileAlternativeTextToolFileMountTest} does for its column.
     *
     * Mutating `$GLOBALS['TCA']` alone is not enough: the DataHandler asks the
     * COMPILED schema, so the schema is rebuilt from the changed array and both
     * the tool's pre-check and the DataHandler read the same shape.
     *
     * @param array<string, mixed> $overrides column keys to set, `config` left alone
     */
    private function overrideTheOpenGraphColumn(array $overrides): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $table = $tca['pages'] ?? null;
        self::assertIsArray($table);
        $columns = $table['columns'] ?? null;
        self::assertIsArray($columns);
        $column = $columns['og_image'] ?? null;
        self::assertIsArray($column);

        $columns['og_image'] = array_replace($column, $overrides);
        $table['columns']    = $columns;
        $tca['pages']        = $table;
        $GLOBALS['TCA']      = $tca;

        $this->getService(TcaSchemaFactory::class)->rebuild($tca);
    }

    private function counter(string $field, int $pageUid = self::PAGE_OPEN): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select($field)
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int)$row[$field] : -1;
    }

    #[Test]
    public function itSetsTheOpenGraphImage(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE]);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('og_image', $result->content);
        self::assertStringContainsString('one.jpg', $result->content);

        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));

        $row = $this->referenceRow(1);
        self::assertSame('pages', $row['tablenames']);
        self::assertSame('og_image', $row['fieldname']);
        self::assertSame(self::PAGE_OPEN, (int)$row['uid_foreign']);
        self::assertSame(self::FILE_ONE, (int)$row['uid_local']);
        self::assertSame(self::PAGE_OPEN, (int)$row['pid']);
        self::assertSame(0, (int)$row['sys_language_uid']);

        // The page has to count the reference, or EXT:seo renders nothing.
        self::assertSame(1, $this->counter('og_image'));
        self::assertSame(0, $this->counter('twitter_image'));
    }

    #[Test]
    public function itSetsTheTwitterImageAndLeavesTheOpenGraphImageAlone(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'twitter_image', 'file' => self::FILE_TWO]);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_TWO, 'deleted' => 0]], $this->references('twitter_image'));
        self::assertSame('twitter_image', $this->referenceRow(1)['fieldname']);
        self::assertSame(1, $this->counter('twitter_image'));

        self::assertSame([], $this->references('og_image'));
        self::assertSame(0, $this->counter('og_image'));
    }

    /**
     * The permitted direction of every permission check: an editor inside the
     * mounts, with the table and field grants, writes what the backend lets
     * them write.
     */
    #[Test]
    public function aNonAdminEditorWithTheGrantsSetsTheImage(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));
        self::assertSame(1, $this->counter('og_image'));
    }

    /**
     * The delete of the old reference travels in the same DataHandler run as
     * the page row, so it is checked against PAGE_EDIT — the only page right the
     * editors hold here. Issued in a run of its own it would need CONTENT_EDIT
     * and fail, leaving two live references.
     */
    #[Test]
    public function aNonAdminEditorReplacesUnderPageEditAlone(): void
    {
        self::assertFalse($this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2)->isError);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO, 'replace' => true], userUid: 2);

        self::assertFalse($result->isError, $result->content);
        self::assertNotNull($result->writeTarget);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
                ['uid' => $result->writeTarget->uid, 'uid_local' => self::FILE_TWO, 'deleted' => 0],
            ],
            $this->references('og_image'),
        );
        self::assertSame(1, $this->counter('og_image'));
    }

    #[Test]
    public function aFieldOutsideTheTwoIsRefused(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'media', 'file' => self::FILE_ONE]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('"media" is not a page field this tool sets', $result->content);
        self::assertStringContainsString('og_image, twitter_image', $result->content);
        self::assertSame([], $this->references('media'));
    }

    #[Test]
    public function aFileOutsideTheActingUsersMountsIsRefused(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_OUTSIDE_MOUNT], userUid: 2);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertSame([], $this->references('og_image'));
    }

    /**
     * A page or file that does not exist and one the user may not reach are
     * refused in the same words, so a refusal never confirms that a uid exists.
     */
    #[Test]
    public function anAbsentAndAnUnreachablePageOrFileRefuseIdentically(): void
    {
        $closedPage  = $this->set(['page' => self::PAGE_CLOSED, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);
        $missingPage = $this->set(['page' => self::PAGE_MISSING, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);
        $outsideFile = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_OUTSIDE_MOUNT], userUid: 2);
        $missingFile = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_MISSING], userUid: 2);

        foreach ([$closedPage, $missingPage, $outsideFile, $missingFile] as $refusal) {
            self::assertTrue($refusal->isError);
            self::assertSame(self::NOT_PERMITTED, $refusal->content);
        }

        self::assertSame([], $this->references('og_image', self::PAGE_CLOSED));
        self::assertSame([], $this->references('og_image'));
    }

    #[Test]
    public function aFileTypeTheFieldDoesNotAcceptIsRefused(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TEXT]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not accept a .txt file', $result->content);
        self::assertSame([], $this->references('og_image'));
        self::assertSame(0, $this->counter('og_image'));
    }

    #[Test]
    public function aTranslatedPageIsRefusedAndTheDefaultLanguagePageIsNamed(): void
    {
        $result = $this->set(['page' => self::PAGE_TRANSLATED, 'field' => 'og_image', 'file' => self::FILE_ONE]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('is a translation', $result->content);
        self::assertStringContainsString('[' . self::PAGE_OPEN . ']', $result->content);
        self::assertSame([], $this->references('og_image', self::PAGE_TRANSLATED));
    }

    #[Test]
    public function anExistingReferenceIsRefusedWithoutReplaceAndNothingChanges(): void
    {
        self::assertFalse($this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE])->isError);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('already', $result->content);
        self::assertStringContainsString('one.jpg', $result->content, 'the refusal names the file referenced now');
        self::assertStringContainsString('"replace": true', $result->content);

        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));
        self::assertSame(1, $this->counter('og_image'));
    }

    #[Test]
    public function withReplaceTheOldReferenceIsSoftDeletedAndTheNewOneExists(): void
    {
        self::assertFalse($this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE])->isError);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO, 'replace' => true]);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('two.jpg', $result->content);
        self::assertStringContainsString('one.jpg', $result->content, 'the success line names what was replaced');
        self::assertNotNull($result->writeTarget);
        self::assertSame('sys_file_reference', $result->writeTarget->table);

        // Soft-deleted through the DataHandler: the old row is still there,
        // flagged, and recoverable — not gone from the table. The new row's uid
        // is read off the result rather than assumed: the translated page
        // follows its parent (`allowLanguageSynchronization`), and core's
        // synchronisation mints reference rows of its own in between.
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
                ['uid' => $result->writeTarget->uid, 'uid_local' => self::FILE_TWO, 'deleted' => 0],
            ],
            $this->references('og_image'),
        );
        self::assertSame(1, $this->counter('og_image'));
    }

    #[Test]
    public function replaceWithoutAnExistingReferenceSimplySets(): void
    {
        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE, 'replace' => true]);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));
        self::assertSame(1, $this->counter('og_image'));
    }

    #[Test]
    public function thePreviewShowsTheCurrentAndTheFutureFile(): void
    {
        self::assertFalse($this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE])->isError);

        $lines = $this->tool->previewCall(
            ['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO, 'replace' => true],
            ToolExecutionContext::fromBackendUser($this->actor(1)),
        );

        self::assertStringContainsString('Open page', $lines[0]);
        self::assertStringContainsString('og_image', $lines[0]);
        self::assertStringContainsString('one.jpg', $lines[1], 'the file referenced now');
        self::assertStringContainsString('two.jpg', $lines[2], 'the file that would be referenced');
        self::assertStringContainsString('REPLACES', implode("\n", $lines));

        // A pure function of the arguments and the current state: nothing was
        // written by asking.
        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));
    }

    #[Test]
    public function thePreviewOfAnEmptyFieldSaysSo(): void
    {
        $lines = $this->tool->previewCall(
            ['page' => self::PAGE_OPEN, 'field' => 'twitter_image', 'file' => self::FILE_TWO],
            ToolExecutionContext::fromBackendUser($this->actor(1)),
        );

        self::assertStringContainsString('(none)', $lines[1]);
        self::assertStringContainsString('two.jpg', $lines[2]);
        self::assertStringNotContainsString('REPLACES', implode("\n", $lines));
    }

    #[Test]
    public function aViewerWhoMayNotReachTheFileGetsNoPreview(): void
    {
        $arguments = ['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_OUTSIDE_MOUNT];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->actor(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->actor(2)));
    }

    /**
     * `og_image` is an exclude field. The DataHandler would create the
     * reference row and drop the page's counter in silence, leaving a reference
     * EXT:seo never renders — so the grant is asked before anything is written.
     */
    #[Test]
    public function anEditorWithoutTheExcludeFieldGrantIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')
            ->update('be_groups', ['non_exclude_fields' => ''], ['uid' => self::EDITOR_GROUP]);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('exclude field', $result->content);
        self::assertStringContainsString('pages:og_image', $result->content);
        self::assertSame([], $this->references('og_image'));
        self::assertSame(0, $this->counter('og_image'));
    }

    /**
     * Core decides "exclude" with a `(bool)` cast (`supportsAccessControl()`),
     * so an installation that writes `'exclude' => 1` puts the column under the
     * same grant. The pre-check has to see that shape too, or the DataHandler
     * drops the page's side for a user the pre-check let through.
     */
    #[Test]
    public function anIntegerExcludeFlagRefusesLikeTheBooleanOne(): void
    {
        $this->overrideTheOpenGraphColumn(['exclude' => 1]);
        $this->connectionPool->getConnectionForTable('be_groups')
            ->update('be_groups', ['non_exclude_fields' => ''], ['uid' => self::EDITOR_GROUP]);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('exclude field', $result->content);
        self::assertSame([], $this->references('og_image'));
        self::assertSame(0, $this->counter('og_image'));
    }

    /**
     * The second shape the DataHandler drops in silence: a column whose
     * `displayCond` is exactly `HIDE_FOR_NON_ADMINS` is skipped for every
     * non-admin, grant or no grant. The refusal names the condition, not a
     * grant the editor holds.
     */
    #[Test]
    public function aColumnHiddenFromNonAdminsIsRefusedBeforeAnythingIsWrittenEvenWithTheGrant(): void
    {
        $this->overrideTheOpenGraphColumn(['displayCond' => 'HIDE_FOR_NON_ADMINS']);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('HIDE_FOR_NON_ADMINS', $result->content);
        self::assertStringNotContainsString('exclude field', $result->content, 'the editor holds the grant; that is not the cause');
        self::assertSame([], $this->references('og_image'));
        self::assertSame(0, $this->counter('og_image'));
    }

    /**
     * The other direction of the same rule: the condition hides nothing from an admin.
     */
    #[Test]
    public function anAdminSetsTheImageOnAColumnHiddenFromNonAdmins(): void
    {
        $this->overrideTheOpenGraphColumn(['displayCond' => 'HIDE_FOR_NON_ADMINS']);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE]);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]], $this->references('og_image'));
        self::assertSame(1, $this->counter('og_image'));
    }

    /**
     * The DataHandler refuses the page row for a user without the `pages`
     * table grant — and would still create the reference row, whose grant the
     * user holds. The tool takes that orphan back.
     */
    #[Test]
    public function aDataHandlerRefusalLeavesNoOrphanReference(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')
            ->update('be_groups', ['tables_modify' => 'sys_file_reference'], ['uid' => self::EDITOR_GROUP]);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertSame([], array_filter($this->references('og_image'), static fn(array $row): bool => $row['deleted'] === 0));
        self::assertSame(0, $this->counter('og_image'));
    }

    /**
     * The read-back is the backstop for whatever the pre-check cannot see. When
     * it fires, the page goes back to what it was: the new reference — and the
     * copy core minted for the translation — is deleted, the counter is 0, and
     * the message names the counter rather than a grant the editor holds or
     * "previous references" that never existed. The next call is not refused
     * as "already has"; once the cause is gone it succeeds.
     */
    #[Test]
    public function aFailedReadBackTakesTheReferenceBackAndLeavesAWayOut(): void
    {
        $this->dropThePageImageFields();

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('counts 0 reference(s)', $result->content);
        self::assertStringNotContainsString('exclude field', $result->content, 'the editor holds the grant; that is not the cause');
        self::assertStringNotContainsString('previous reference', $result->content, 'there was none');
        self::assertSame(
            [],
            array_filter($this->references('og_image'), static fn(array $row): bool => $row['deleted'] === 0),
            'no live reference in any language is left on the page',
        );
        self::assertSame(0, $this->counter('og_image'));

        $this->stopDroppingThePageImageFields();

        $retry = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2);

        self::assertFalse($retry->isError, $retry->content);
        self::assertNotNull($retry->writeTarget);
        self::assertSame(
            [['uid' => $retry->writeTarget->uid, 'uid_local' => self::FILE_ONE, 'deleted' => 0]],
            array_values(array_filter($this->references('og_image'), static fn(array $row): bool => $row['deleted'] === 0)),
        );
        self::assertSame(1, $this->counter('og_image'));
    }

    /**
     * In a replace call the previous references are deleted only AFTER the
     * read-back has accepted the new one, so a failed read-back can put them
     * back as they were: live, and counted by the page. Two references — the
     * TCA permits them, FormEngine writes them — so the stale counter cannot
     * pass for the expected one by coincidence.
     *
     * Without the translated page: putting pre-existing children back makes
     * core's `DataMapProcessor` localise them for a parent-following
     * translation, and `localize` needs a site language this fixture does not
     * declare. In an installation those copies exist from the save that wrote
     * the children; the claim here is about the default-language page alone.
     */
    #[Test]
    public function aFailedReadBackInAReplaceCallKeepsThePreviousReferences(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->delete('pages', ['uid' => self::PAGE_TRANSLATED]);
        $this->seedReference(1, self::FILE_ONE, 'og_image');
        $this->seedReference(2, self::FILE_TWO, 'og_image');
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['og_image' => 2], ['uid' => self::PAGE_OPEN]);
        $this->dropThePageImageFields();

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE, 'replace' => true], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('counts 2 reference(s)', $result->content);
        self::assertStringNotContainsString('previous reference', $result->content);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0],
                ['uid' => 2, 'uid_local' => self::FILE_TWO, 'deleted' => 0],
                ['uid' => 3, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
            ],
            $this->references('og_image'),
        );
        self::assertSame(2, $this->counter('og_image'));
    }

    /**
     * The state the first version of the tool left behind when the page's side
     * was dropped: a default-language reference and the copy core minted for
     * the translation, both live on the DEFAULT-LANGUAGE page, counter 0. The
     * tool counts default-language references only, so the refusal names the
     * file once, and `replace` deletes the pair — core deletes a localization
     * with its parent — and sets the page right.
     */
    #[Test]
    public function aBrokenPairLeftByAnEarlierRunIsNamedOnceAndReplacedCleanly(): void
    {
        $this->seedReference(1, self::FILE_ONE, 'og_image');
        $this->seedReference(2, self::FILE_ONE, 'og_image', ['sys_language_uid' => 1, 'l10n_parent' => 1]);

        $refusal = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO]);

        self::assertTrue($refusal->isError);
        self::assertSame(1, substr_count($refusal->content, 'one.jpg'), $refusal->content);

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO, 'replace' => true]);

        self::assertFalse($result->isError, $result->content);
        self::assertNotNull($result->writeTarget);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
                ['uid' => 2, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
                ['uid' => $result->writeTarget->uid, 'uid_local' => self::FILE_TWO, 'deleted' => 0],
            ],
            $this->references('og_image'),
        );
        self::assertSame(1, $this->counter('og_image'));
    }

    /**
     * The other half of the read-back, asked after the cmdmap: a replaced
     * reference the delete did not remove is still live beside the new one.
     * The new one is taken back, the previous one stays counted, and the
     * message names what is still there — which is true when it fires,
     * because the check comes after the delete had its chance.
     */
    #[Test]
    public function aPreviousReferenceTheCmdmapDidNotRemoveIsReportedAndTheNewOneTakenBack(): void
    {
        self::assertFalse($this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE], userUid: 2)->isError);
        $this->dropTheReferenceDeleteCommand();

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_TWO, 'replace' => true], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('still carries 1 other live reference(s)', $result->content);
        self::assertStringContainsString('taken back', $result->content);
        self::assertSame(
            [['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0]],
            array_values(array_filter($this->references('og_image'), static fn(array $row): bool => $row['deleted'] === 0)),
            'the replaced reference is the only live one; the new one was taken back',
        );
        self::assertSame(1, $this->counter('og_image'));
    }

    /**
     * When the cmdmap removed some of several previous references and not the
     * rest, the list the call found goes back into the page's field, and the
     * page ends up counting what is still live: the DataHandler relates only
     * the live rows of that list. Two references written past the tool, as
     * FormEngine leaves them; without the translated page, for the reason the
     * replace case gives.
     */
    #[Test]
    public function aPartlyRemovedListLeavesThePageCountingWhatIsStillLive(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->delete('pages', ['uid' => self::PAGE_TRANSLATED]);
        $this->seedReference(1, self::FILE_ONE, 'og_image');
        $this->seedReference(2, self::FILE_TWO, 'og_image');
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['og_image' => 2], ['uid' => self::PAGE_OPEN]);
        $this->dropTheReferenceDeleteCommand();

        $result = $this->set(['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE, 'replace' => true], userUid: 2);

        self::assertTrue($result->isError);
        self::assertStringContainsString('still carries 1 other live reference(s)', $result->content);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => self::FILE_ONE, 'deleted' => 0],
                ['uid' => 2, 'uid_local' => self::FILE_TWO, 'deleted' => 1],
                ['uid' => 3, 'uid_local' => self::FILE_ONE, 'deleted' => 1],
            ],
            $this->references('og_image'),
        );
        self::assertSame(1, $this->counter('og_image'), 'the page counts the one reference that is still live');
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $admin            = $this->actor(1);
        $admin->workspace = 1;

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'field' => 'og_image', 'file' => self::FILE_ONE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
        self::assertSame([], $this->references('og_image'));
    }
}
