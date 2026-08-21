<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToContentElementTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
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
 * The attach writer against the real DataHandler and a real storage.
 *
 * Functional rather than unit because the interesting behaviour is what ends up
 * in `sys_file_reference` AND in the element's own counter — and because the
 * defect this tool exists to avoid is invisible to a mock: a single-pass write
 * leaves the row behind with a counter one short and reports no error at all.
 */
#[CoversClass(AttachFileToContentElementTool::class)]
final class AttachFileToContentElementToolTest extends AbstractFunctionalTestCase
{
    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private ConnectionPool $connectionPool;

    private AttachFileToContentElementTool $tool;

    private int $pageUid = 0;

    private int $elementUid = 0;

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
        $storage->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
            'tables_modify' => 'tt_content,sys_file_reference',
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
            'uid' => 1, 'pid' => 0, 'title' => 'Host page', 'doktype' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 9, 'perms_group' => Permission::ALL,
            'perms_everybody' => Permission::ALL,
        ]);
        $this->pageUid = (int)$pages->lastInsertId();

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'pid' => $this->pageUid, 'header' => 'An element', 'CType' => 'textmedia',
            'assets' => 0, 'sys_language_uid' => 0,
        ]);
        $this->elementUid = (int)$content->lastInsertId();

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // write guard refuses without it rather than crashing halfway through.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $this->tool = new AttachFileToContentElementTool($this->connectionPool, $gate);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function actor(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        // `CType` is an explicitAllow field, so a non-admin needs the value
        // listed before the DataHandler will touch the element at all.
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:textmedia';
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
    private function references(string $field = 'assets'): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'uid_local', 'sorting_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($this->elementUid, Connection::PARAM_INT)),
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

    private function counter(string $field = 'assets'): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select($field)
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($this->elementUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int)$row[$field] : -1;
    }

    #[Test]
    public function itCreatesOneReferenceAndLeavesTheCounterConsistent(): void
    {
        $result = $this->attach(['content_element' => $this->elementUid, 'file' => 1, 'field' => 'assets']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(
            [['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1]],
            $this->references(),
        );
        // The whole reason this tool writes twice: a single pass leaves this at 0.
        self::assertSame(1, $this->counter(), 'The element must count the reference it now holds.');
    }

    #[Test]
    public function aSecondFileIsAppendedAfterTheFirst(): void
    {
        self::assertFalse($this->attach(['content_element' => $this->elementUid, 'file' => 1, 'field' => 'assets'])->isError);
        $second = $this->attach(['content_element' => $this->elementUid, 'file' => 2, 'field' => 'assets']);

        self::assertFalse($second->isError, $second->content);
        self::assertSame(
            [
                ['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1],
                ['uid' => 2, 'uid_local' => 2, 'sorting_foreign' => 2],
            ],
            $this->references(),
            'The new reference must sort AFTER the one already there.',
        );
        self::assertSame(2, $this->counter());
    }

    #[Test]
    public function theOptionalTextsLandOnTheReference(): void
    {
        $result = $this->attach([
            'content_element' => $this->elementUid,
            'file'            => 1,
            'field'           => 'assets',
            'title'           => 'A caption',
            'alternative'     => 'A description of the image',
        ]);

        self::assertFalse($result->isError, $result->content);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('title', 'alternative', 'tablenames', 'fieldname', 'uid_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('A caption', $row['title']);
        self::assertSame('A description of the image', $row['alternative']);
        self::assertSame('tt_content', $row['tablenames']);
        self::assertSame('assets', $row['fieldname']);
        self::assertSame($this->elementUid, (int)$row['uid_foreign']);
    }

    /**
     * The refusal the issue did not ask for and the live TCA demands: `image`
     * declares fourteen extensions and `.txt` is not among them.
     */
    #[Test]
    public function aFileTheFieldDoesNotAcceptIsRefused(): void
    {
        $result = $this->attach(['content_element' => $this->elementUid, 'file' => 3, 'field' => 'assets']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not accept a .txt file', $result->content);
        self::assertSame([], $this->references());
    }

    /**
     * `textmedia` shows `assets` and nothing else, so naming `image` — a real
     * file field on the same table — is still refused.
     */
    #[Test]
    public function aFieldTheElementTypeDoesNotOfferIsRefused(): void
    {
        $result = $this->attach(['content_element' => $this->elementUid, 'file' => 1, 'field' => 'image']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not a file field of content type', $result->content);
        self::assertSame([], $this->references('image'));
    }

    /**
     * No CType that ships with the core offers two of the three fields —
     * measured, not assumed: `textpic` and `image` show `image`, `textmedia`
     * shows `assets`, and nothing shows two. The branch is still right, because
     * a third-party content type may show two, so the type is registered here
     * rather than left as an untested claim.
     */
    #[Test]
    public function anAmbiguousFieldIsRefusedRatherThanGuessed(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        self::assertIsArray($tca['tt_content']);
        self::assertIsArray($tca['tt_content']['types']);
        $tca['tt_content']['types']['nrllm_twofields'] = ['showitem' => 'header, image, assets'];
        $GLOBALS['TCA'] = $tca;
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['CType' => 'nrllm_twofields'], ['uid' => $this->elementUid]);

        $result = $this->attach(['content_element' => $this->elementUid, 'file' => 1]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Name the one you mean', $result->content);
        self::assertSame([], $this->references());
        self::assertSame([], $this->references('image'));
    }

    #[Test]
    public function aFileOutsideTheActingUsersMountsIsRefused(): void
    {
        $result = $this->attach(['content_element' => $this->elementUid, 'file' => 4, 'field' => 'assets'], userUid: 2);

        self::assertTrue($result->isError);
        self::assertSame('Content element or file not found, or not permitted.', $result->content);
        self::assertSame([], $this->references());
    }

    /**
     * A file that does not exist and one the user may not reach are refused in
     * the same words, so a refusal never confirms that a uid exists.
     */
    #[Test]
    public function anAbsentFileAndAnUnreachableOneRefuseIdentically(): void
    {
        $absent = $this->attach(['content_element' => $this->elementUid, 'file' => 999, 'field' => 'assets'], userUid: 2);
        $unreachable = $this->attach(['content_element' => $this->elementUid, 'file' => 4, 'field' => 'assets'], userUid: 2);

        self::assertTrue($absent->isError);
        self::assertSame($absent->content, $unreachable->content);
    }

    #[Test]
    public function anElementOnAPageTheUserMayNotEditIsRefused(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'pid' => 0, 'title' => 'Closed', 'doktype' => 1,
            'perms_userid' => 99, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $closedPage = (int)$pages->lastInsertId();

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['pid' => $closedPage, 'header' => 'Closed element', 'CType' => 'textmedia', 'sys_language_uid' => 0]);

        $closedElement = (int)$content->lastInsertId();

        $result = $this->attach(['content_element' => $closedElement, 'file' => 1, 'field' => 'assets'], userUid: 2);

        self::assertTrue($result->isError);
        self::assertSame('Content element or file not found, or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewNamesTheElementTheFieldAndTheFile(): void
    {
        $lines = $this->tool->previewCall(
            ['content_element' => $this->elementUid, 'file' => 1, 'field' => 'assets', 'title' => 'A caption'],
            ToolExecutionContext::fromBackendUser($this->actor(1)),
        );

        self::assertStringContainsString('An element', $lines[0]);
        self::assertStringContainsString('assets', $lines[1]);
        self::assertStringContainsString('0 reference(s) → 1', $lines[1]);
        self::assertStringContainsString('one.jpg', $lines[2]);
        self::assertStringContainsString('A caption', implode("\n", $lines));
    }

    #[Test]
    public function aViewerWhoMayNotReachTheFileGetsNoPreview(): void
    {
        $arguments = ['content_element' => $this->elementUid, 'file' => 4, 'field' => 'assets'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->actor(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->actor(2)));
    }

    /**
     * `title`, `alternative` and `description` are `exclude` fields, so the
     * DataHandler drops them for a user without the grant — silently, with an
     * empty errorLog. Reported as a failure, and the half-written reference is
     * removed rather than left behind.
     */
    #[Test]
    public function aSilentlyDroppedCaptionIsReportedAndTheReferenceIsRemoved(): void
    {
        $result = $this->attach([
            'content_element' => $this->elementUid,
            'file'            => 1,
            'field'           => 'assets',
            'title'           => 'A caption the editor may not set',
        ], userUid: 2);

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"title" did not take', $result->content);
        self::assertSame([], $this->references(), 'A refused call must leave the element as it found it.');
        self::assertSame(0, $this->counter());
    }

    #[Test]
    public function theSameWriteSucceedsWithTheExcludeFieldGrant(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['non_exclude_fields' => 'sys_file_reference:title'],
            ['uid' => 9],
        );

        $result = $this->attach([
            'content_element' => $this->elementUid,
            'file'            => 1,
            'field'           => 'assets',
            'title'           => 'A caption the editor may set',
        ], userUid: 2);

        self::assertFalse($result->isError, $result->content);
        self::assertCount(1, $this->references());
    }

}
