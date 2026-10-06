<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
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

        $this->tool = new DeleteRecordTool($this->connectionPool, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
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
        $arguments = ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION];

        $english = $this->previewIn('en', $arguments);
        self::assertContains('Language: translation, not the default language', $english);
        self::assertContains(
            'Deleted with it: content elements in this language on the default-language page: 1, plus every other record in that language there',
            $english,
        );

        $german = $this->previewIn('de', $arguments);
        self::assertContains('Sprache: Übersetzung, nicht die Standardsprache', $german);
        self::assertContains(
            'Wird mitgelöscht: Inhaltselemente in dieser Sprache auf der Seite in Standardsprache: 1, dazu jeder andere Datensatz dieser Sprache dort',
            $german,
        );
    }

    /**
     * What core's delete would take along from another workspace, one kind
     * per case: rows it discards and rows it strands.
     *
     * @return iterable<string, array{array<string, mixed>, list<array{string, array<string, mixed>}>}>
     */
    public static function deletesThatWouldTakeADraftAlong(): iterable
    {

        yield 'a version of the element' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_oid' => self::ELEMENT])],
        ];
        yield 'a version of its translation' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_oid' => self::TRANSLATION, 'sys_language_uid' => 1])],
        ];
        yield 'a new workspace translation of the element' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_state' => 1, 'sys_language_uid' => 2, 'l18n_parent' => self::ELEMENT])],
        ];
        yield 'a version of a file reference of the element' => [
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            [
                self::fileReference(['uid' => 60, 'pid' => self::PAGE_OPEN, 'uid_foreign' => self::ELEMENT]),
                self::fileReference(['uid' => 61, 'pid' => self::PAGE_OPEN, 'uid_foreign' => self::ELEMENT, 't3ver_wsid' => 1, 't3ver_oid' => 60]),
            ],
        ];
        yield 'a new element in the branch, stranded' => [
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            [self::draftElement(['pid' => self::SUBPAGE, 't3ver_state' => 1])],
        ];
        yield 'a move version of an element in the branch, on another page' => [
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_oid' => self::ELEMENT_ON_SUBPAGE, 't3ver_state' => 4])],
        ];
        yield 'content of the language of a page translation' => [
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_oid' => self::TRANSLATION, 'sys_language_uid' => 1])],
        ];
        yield 'a new element in the language of a page translation, stranded' => [
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            [self::draftElement(['pid' => self::PAGE_OPEN, 't3ver_state' => 1, 'sys_language_uid' => 1])],
        ];
        yield 'a move version of content in the language of a page translation, on another page' => [
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            [self::draftElement(['pid' => self::PAGE_WITH_BRANCH, 't3ver_oid' => self::TRANSLATION, 't3ver_state' => 4, 'sys_language_uid' => 1])],
        ];
        yield 'a file reference of the language of a page translation' => [
            ['table' => 'pages', 'uid' => self::PAGE_OPEN_TRANSLATION],
            [
                self::fileReference(['uid' => 62, 'pid' => self::PAGE_OPEN, 'uid_foreign' => self::TRANSLATION, 'sys_language_uid' => 1]),
                self::fileReference(['uid' => 63, 'pid' => self::PAGE_OPEN, 'uid_foreign' => self::TRANSLATION, 'sys_language_uid' => 1, 't3ver_wsid' => 1, 't3ver_oid' => 62]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{string, array<string, mixed>}
     */
    private static function draftElement(array $fields): array
    {
        return ['tt_content', ['uid' => 50, 'colPos' => 0, 'CType' => 'text', 'header' => 'Draft', 't3ver_wsid' => 1, ...$fields]];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{string, array<string, mixed>}
     */
    private static function fileReference(array $fields): array
    {
        return ['sys_file_reference', ['uid_local' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', ...$fields]];
    }

    /**
     * @param array<string, mixed>                      $arguments
     * @param list<array{string, array<string, mixed>}> $rows
     */
    #[Test]
    #[DataProvider('deletesThatWouldTakeADraftAlong')]
    public function aDeleteThatWouldTakeAWorkspaceDraftAlongIsRefused(array $arguments, array $rows): void
    {
        foreach ($rows as [$table, $row]) {
            $this->connectionPool->getConnectionForTable($table)->insert($table, $row);
        }

        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('would take along 1 workspace draft(s)', $result->content);
        self::assertSame(0, $this->deletedOf($this->toTable($arguments), $this->toUid($arguments)));
    }

    #[Test]
    public function aDraftOfAnotherRecordDoesNotStandInTheWay(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 50, 'pid' => self::PAGE_OPEN, 'colPos' => 0, 'CType' => 'text', 'header' => 'Draft',
            't3ver_wsid' => 1, 't3ver_oid' => self::ELEMENT_ON_SUBPAGE,
        ]);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->deletedOf('tt_content', self::ELEMENT));
    }

    #[Test]
    public function aDraftFoundTwiceIsCountedOnce(): void
    {
        // A version of the subpage: a version of a deleted page, and a row
        // stored on a deleted page.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 70, 'pid' => self::PAGE_WITH_BRANCH, 'title' => 'Leaf, drafted', 'doktype' => 1,
            't3ver_wsid' => 1, 't3ver_oid' => self::SUBPAGE,
        ]);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertStringContainsString('would take along 1 workspace draft(s)', $result->content);
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
        $arguments = ['table' => 'tt_content', 'uid' => self::ELEMENT];

        // ADR-213 and rule 25 of the editorial guidelines: the object, where
        // it is, its language versions, what points at it, whether it comes
        // back — each as a line, the identifiers last.
        self::assertSame([
            'Delete content element',
            'Content element: “Doomed”',
            'Location: on page “Open”',
            'Language: default language',
            'Translations: 1, deleted with it',
            'Important: references from other records: 1. Those links or relations will point at a deleted record.',
            'Recoverable: yes, from the recycler',
            'Technical details: table tt_content, UID 20, page UID 2, language UID 0, translations UID 21',
        ], $this->previewIn('en', $arguments));
        self::assertSame([
            'Inhaltselement löschen',
            'Inhaltselement: „Doomed“',
            'Ort: auf der Seite „Open“',
            'Sprache: Standardsprache',
            'Übersetzungen: 1, werden mitgelöscht',
            'Wichtig: Verweise von anderen Datensätzen: 1. Diese Links oder Verknüpfungen führen danach auf einen gelöschten Datensatz.',
            'Wiederherstellbar: ja, über den Papierkorb',
            'Technische Details: Tabelle tt_content, UID 20, Seite UID 2, Sprach-UID 0, Übersetzungen UID 21',
        ], $this->previewIn('de', $arguments));
        self::assertSame(0, $this->deletedOf('tt_content', self::ELEMENT));
    }

    #[Test]
    public function thePreviewOfAPageCountsItsBranchAndContent(): void
    {
        $arguments = ['table' => 'pages', 'uid' => self::PAGE_WITH_BRANCH, 'include_subpages' => true];

        $english = $this->previewIn('en', $arguments);
        self::assertSame('Delete page', $english[0]);
        self::assertContains('Page: “Branch”', $english);
        self::assertContains('Translations: none', $english);
        self::assertContains('Subpages: 1, deleted with it (plus 0 translations of them)', $english);
        // The shortcut on page 3 and the element on its subpage, counted by
        // the table's title in the user's language — counts only, never
        // titles. The title comes from core's TCA label, so what the German
        // line says here is whatever core ships for German (this environment
        // has no core language pack, hence the English title in both).
        self::assertContains('Records stored on the page (all languages), deleted with it: Page Content: 2', $english);
        self::assertContains('References from other records: none', $english);
        self::assertContains('Technical details: table pages, UID 3, language UID 0, subpages UID 4', $english);

        $german = $this->previewIn('de', $arguments);
        self::assertSame('Seite löschen', $german[0]);
        self::assertContains('Seite: „Branch“', $german);
        self::assertContains('Übersetzungen: keine', $german);
        self::assertContains('Unterseiten: 1, werden mitgelöscht (dazu 0 Übersetzungen davon)', $german);
        self::assertContains('Auf der Seite gespeicherte Datensätze (alle Sprachen), werden mitgelöscht: Page Content: 2', $german);
        self::assertContains('Verweise von anderen Datensätzen: keine', $german);
        self::assertContains('Technische Details: Tabelle pages, UID 3, Sprach-UID 0, Unterseiten UID 4', $german);
    }

    #[Test]
    public function thePreviewOfAPageWithoutSubpagesOrRecordsSaysSo(): void
    {
        $arguments = ['table' => 'pages', 'uid' => self::PAGE_CLOSED];

        self::assertContains('Subpages: none', $this->previewIn('en', $arguments));
        self::assertContains('Records stored on the page: none', $this->previewIn('en', $arguments));
        self::assertContains('Unterseiten: keine', $this->previewIn('de', $arguments));
        self::assertContains('Auf der Seite gespeicherte Datensätze: keine', $this->previewIn('de', $arguments));
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['table' => 'pages', 'uid' => self::PAGE_CLOSED];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor()));
    }

    /**
     * The preview as the run's acting administrator reads it, in that user's
     * language (ADR-213): the `lang` column of the backend user.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewIn(string $language, array $arguments): array
    {
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = $language;

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));
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
