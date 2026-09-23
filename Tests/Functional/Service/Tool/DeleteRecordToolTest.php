<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\DeleteRecordTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of `delete_record` against a real database and the real
 * DataHandler (ADR-198): what core deletes with a record is counted before
 * the approval and refused where the acting user may not delete all of it.
 */
#[CoversClass(DeleteRecordTool::class)]
final class DeleteRecordToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const SITE_ROOT = 1;

    private const PAGE_OPEN = 2;

    private const PAGE_WITH_BRANCH = 3;

    private const SUBPAGE = 4;

    private const PAGE_CLOSED = 5;

    private const SUBPAGE_CLOSED = 6;

    private const PAGE_WITH_CLOSED_BRANCH = 7;

    private const CONTENT_CLOSED = 8;

    private const PAGE_OPEN_TRANSLATION = 9;

    private const ELEMENT = 20;

    private const TRANSLATION = 21;

    private const ELEMENT_ON_SUBPAGE = 22;

    private const ELEMENT_ON_CLOSED = 23;

    private const SHORTCUT = 24;

    private DeleteRecordTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::SITE_ROOT, 0, 'Root', Permission::ALL, 1],
            [self::PAGE_OPEN, self::SITE_ROOT, 'Open', Permission::ALL, 0],
            [self::PAGE_WITH_BRANCH, self::SITE_ROOT, 'Branch', Permission::ALL, 0],
            [self::SUBPAGE, self::PAGE_WITH_BRANCH, 'Leaf', Permission::ALL, 0],
            [self::PAGE_CLOSED, self::SITE_ROOT, 'Closed', Permission::ALL & ~Permission::PAGE_DELETE, 0],
            [self::CONTENT_CLOSED, self::SITE_ROOT, 'Content closed', Permission::ALL & ~Permission::CONTENT_EDIT, 0],
            [self::PAGE_WITH_CLOSED_BRANCH, self::SITE_ROOT, 'Mixed branch', Permission::ALL, 0],
            [self::SUBPAGE_CLOSED, self::PAGE_WITH_CLOSED_BRANCH, 'Closed leaf', Permission::ALL & ~Permission::PAGE_DELETE, 0],
        ] as [$uid, $pid, $title, $everybody, $siteRoot]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid,
                'is_siteroot' => $siteRoot,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN_TRANSLATION, 'pid' => self::SITE_ROOT, 'title' => 'Offen', 'doktype' => 1,
            'slug' => '/offen', 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE_OPEN,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, self::PAGE_OPEN, 'Doomed', 0, 0],
            [self::TRANSLATION, self::PAGE_OPEN, 'Dem Untergang geweiht', 1, self::ELEMENT],
            [self::ELEMENT_ON_SUBPAGE, self::SUBPAGE, 'Deep', 0, 0],
            [self::ELEMENT_ON_CLOSED, self::CONTENT_CLOSED, 'Guarded', 0, 0],
            [self::SHORTCUT, self::PAGE_WITH_BRANCH, 'Points at 20', 0, 0],
        ] as [$uid, $pid, $header, $language, $parent]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => 'text', 'header' => $header,
                'sys_language_uid' => $language, 'l18n_parent' => $parent,
            ]);
        }

        // The reference index row a `shortcut` element pointing at element 20
        // would have; written directly, as the index is not what is tested.
        $this->connectionPool->getConnectionForTable('sys_refindex')->insert('sys_refindex', [
            'hash' => str_repeat('a', 32), 'tablename' => 'tt_content', 'recuid' => self::SHORTCUT, 'field' => 'records',
            'ref_table' => 'tt_content', 'ref_uid' => self::ELEMENT, 'workspace' => 0,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1']);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new DeleteRecordTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminDeletesAnElementAndItsTranslationGoesWithIt(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Deleted tt_content [20] "Doomed" with 1 translation(s)', $result->content);
        self::assertSame(1, $this->deletedOf('tt_content', self::ELEMENT));
        self::assertSame(1, $this->deletedOf('tt_content', self::TRANSLATION));
        self::assertSame(WriteKind::DELETED, $result->writeKind);
        self::assertSame(self::ELEMENT, $result->writeTarget?->uid);
    }

    #[Test]
    public function anEditorMayNotDeleteAnElementWhoseTranslationIsInALanguageTheyMayNotEdit(): void
    {
        $editor                                 = $this->editor();
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('translations in language(s) 1', $result->content);
        self::assertSame(0, $this->deletedOf('tt_content', self::ELEMENT));
        self::assertSame(0, $this->deletedOf('tt_content', self::TRANSLATION));
    }

    #[Test]
    public function anEditorDeletesAnElementOnAPageTheyMayEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->deletedOf('tt_content', self::ELEMENT));
    }

    #[Test]
    public function anEditorMayNotDeleteOnAPageTheyMayNotEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_CLOSED],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(0, $this->deletedOf('tt_content', self::ELEMENT_ON_CLOSED));
    }

    #[Test]
    public function aPageWithSubpagesIsRefusedUnlessTheBranchIsAskedFor(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('page [3] has 1 subpage(s)', $result->content);
        self::assertStringContainsString('include_subpages', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::PAGE_WITH_BRANCH));
        self::assertSame(0, $this->deletedOf('pages', self::SUBPAGE));
    }

    #[Test]
    public function aPageIsDeletedWithItsBranchAndItsContentWhenAskedFor(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('with 1 subpage(s)', $result->content);
        self::assertSame(1, $this->deletedOf('pages', self::PAGE_WITH_BRANCH));
        self::assertSame(1, $this->deletedOf('pages', self::SUBPAGE));
        self::assertSame(1, $this->deletedOf('tt_content', self::ELEMENT_ON_SUBPAGE));
    }

    #[Test]
    public function anEditorMayNotDeleteABranchHoldingAPageTheyMayNotDelete(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_CLOSED_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('holds a page the acting backend user may not delete', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::PAGE_WITH_CLOSED_BRANCH));
        self::assertSame(0, $this->deletedOf('pages', self::SUBPAGE_CLOSED));
    }

    #[Test]
    public function anEditorMayNotDeleteAPageWithoutTheDeletePermission(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_CLOSED],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::PAGE_CLOSED));
    }

    #[Test]
    public function anEditorMayNotDeleteAnElementWhoseTranslationIsLocked(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['editlock' => 1], ['uid' => self::TRANSLATION]);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        // Core would delete the element and refuse the locked translation,
        // leaving half a delete; the tool refuses before either happens.
        self::assertTrue($result->isError);
        self::assertStringContainsString('may not edit (language, lock or content type)', $result->content);
        self::assertSame(0, $this->deletedOf('tt_content', self::ELEMENT));
    }

    #[Test]
    public function anEditorMayNotDeleteABranchHoldingContentInALanguageTheyMayNotEdit(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 30, 'pid' => self::SUBPAGE, 'colPos' => 0, 'CType' => 'text', 'header' => 'Tief',
            'sys_language_uid' => 1, 'l18n_parent' => 0,
        ]);
        $editor                                 = $this->editor();
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('holds content in language 1', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::PAGE_WITH_BRANCH));
    }

    #[Test]
    public function anEditorMayNotDeleteAPageHoldingRecordsOfATableTheyMayNotModify(): void
    {
        $editor                             = $this->editor();
        $editor->groupData['tables_modify'] = 'pages';

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('holds records of tt_content', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::PAGE_WITH_BRANCH));
    }

    #[Test]
    public function thePreviewOfAPageTranslationCountsTheContentOfItsLanguage(): void
    {
        $lines = $this->tool->previewCall(
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertContains(
            'with the 1 content element(s) in language 1 on its default-language page, and every other record in that language there',
            $lines,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function deletesThatWouldDiscardADraft(): iterable
    {
        yield 'a version of the element' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ['pid' => self::PAGE_OPEN, 't3ver_oid' => self::ELEMENT, 't3ver_state' => 0, 'sys_language_uid' => 0],
        ];
        yield 'a version of its translation' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ['pid' => self::PAGE_OPEN, 't3ver_oid' => self::TRANSLATION, 't3ver_state' => 0, 'sys_language_uid' => 1],
        ];
        yield 'a new element in the branch' => [
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ['pid' => self::SUBPAGE, 't3ver_oid' => 0, 't3ver_state' => 1, 'sys_language_uid' => 0],
        ];
        yield 'content of the language of a page translation' => [
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            ['pid' => self::PAGE_OPEN, 't3ver_oid' => self::TRANSLATION, 't3ver_state' => 0, 'sys_language_uid' => 1],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $draft
     */
    #[Test]
    #[DataProvider('deletesThatWouldDiscardADraft')]
    public function aDeleteThatWouldDiscardAWorkspaceDraftIsRefused(array $arguments, array $draft): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 50, 'colPos' => 0, 'CType' => 'text', 'header' => 'Draft', 't3ver_wsid' => 1, ...$draft,
        ]);

        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('would discard 1 workspace draft(s)', $result->content);
        self::assertSame(0, $this->deletedOf($this->toTable($arguments), $this->toUid($arguments)));
        self::assertSame(0, $this->deletedOf('tt_content', 50));
    }

    #[Test]
    public function aSiteRootIsNeverDeleted(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::SITE_ROOT, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is a site root', $result->content);
        self::assertSame(0, $this->deletedOf('pages', self::SITE_ROOT));
    }

    #[Test]
    public function aMissingRecordIsRefusedInTheSameWordsAsAForbiddenOne(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 987654],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewCountsWhatGoesAlongAndWhatStillPointsAtItAndDeletesNothing(): void
    {
        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertSame('Delete tt_content [20] "Doomed" on page [2] "Open", language 0', $lines[0]);
        self::assertSame('with its 1 translation(s): [21]', $lines[1]);
        self::assertStringContainsString('still referenced from 1 other record(s)', $lines[2]);
        self::assertSame(0, $this->deletedOf('tt_content', self::ELEMENT));
    }

    #[Test]
    public function thePreviewOfAPageCountsItsBranchAndContent(): void
    {
        $lines = $this->tool->previewCall(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertContains('with 1 subpage(s): [4], and 0 translation(s) of them', $lines);
        // The shortcut on page 3 and the element on its subpage, counted by
        // table — counts only, never titles.
        self::assertContains('with the records stored on the page(s), in every language: tt_content 2', $lines);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['table' => 'pages', 'uid' => self::PAGE_CLOSED];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor()));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toTable(array $arguments): string
    {
        return is_string($arguments['table'] ?? null) ? $arguments['table'] : '';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toUid(array $arguments): int
    {
        return is_int($arguments['uid'] ?? null) ? $arguments['uid'] : 0;
    }

    private function editor(): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages,tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        return $editor;
    }

    private function deletedOf(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $deleted = $queryBuilder
            ->select('deleted')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        self::assertNotFalse($deleted, sprintf('%s:%d must still exist as a row', $table, $uid));

        return (int)$deleted;
    }
}
