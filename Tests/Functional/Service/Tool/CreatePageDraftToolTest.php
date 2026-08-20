<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the sixth writing tool — the first that creates a page
 * (ADR-180), against a real database and the real
 * {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 *
 * The assertion this file exists for is that the page is HIDDEN. Everything
 * else about the tool is a narrowing of what an editor could do by hand; the
 * hidden state is the one property that makes a machine-drafted page safe, and
 * it must hold without any argument asking for it.
 */
#[CoversClass(CreatePageDraftTool::class)]
final class CreatePageDraftToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** A page only the admin may create pages under. */
    private const PARENT_CLOSED = 1;

    /** A page every backend user may create pages under. */
    private const PARENT_OPEN = 2;

    /** An existing subpage of the open parent, the anchor for positioning. */
    private const EXISTING_SUBPAGE = 20;

    private const DRAFTED_TITLE = 'Drafted page';

    private CreatePageDraftTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => self::PARENT_CLOSED, 'pid' => 0, 'title' => 'Closed', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $pages->insert('pages', [
            'uid' => self::PARENT_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 2,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid' => self::EXISTING_SUBPAGE, 'pid' => self::PARENT_OPEN, 'title' => 'Already there', 'doktype' => 1,
            'slug' => '/open/already-there', 'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreatePageDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theCreatedPageIsHiddenEvenThoughNothingAskedForIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => self::DRAFTED_TITLE, 'nav_title' => 'Drafted'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden page', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);

        $row = $this->createdPage();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted page must never be visible');
        self::assertSame(self::PARENT_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame(1, (int)($row['doktype'] ?? 0), 'only a standard page is ever created');
        self::assertSame(self::DRAFTED_TITLE, $row['title'] ?? null);
        self::assertSame('Drafted', $row['nav_title'] ?? null);
        self::assertSame(0, (int)($row['sys_language_uid'] ?? -1));

        // The success message hands the model the uid it needs for the next
        // call, and names the tool to make it with.
        self::assertStringContainsString(sprintf('page %d', (int)$row['uid']), $result->content);
        self::assertStringContainsString('create_content_element_draft', $result->content);
    }

    #[Test]
    public function theSlugIsGeneratedByTheDataHandlerAndReportedBack(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => self::DRAFTED_TITLE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $slug = $this->createdPage()['slug'] ?? '';
        self::assertIsString($slug);
        self::assertNotSame('', $slug, 'the DataHandler must have filled the slug nobody passed');
        self::assertStringContainsString('drafted-page', $slug);
        self::assertStringContainsString($slug, $result->content);
    }

    #[Test]
    public function anAnchorPlacesThePageAfterIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => 'After the existing one', 'after_page_uid' => self::EXISTING_SUBPAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $created = $this->createdPage();
        self::assertSame(self::PARENT_OPEN, (int)($created['pid'] ?? 0), 'a negative pid must resolve to the parent');
        self::assertGreaterThan(
            (int)$this->pageRow(self::EXISTING_SUBPAGE)['sorting'],
            (int)($created['sorting'] ?? 0),
            'the new page must sort after its anchor',
        );
    }

    #[Test]
    public function theSysLogEntryNamesTheActingUser(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => 'Logged'],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($result->isError, $result->content);

        $created = $this->createdPage();
        self::assertSame(
            [1],
            array_values(array_unique($this->sysLogUserIdsFor((int)($created['uid'] ?? 0)))),
        );
    }

    #[Test]
    public function anEditorMayNotCreateUnderAPageTheyMayNotCreateUnder(): void
    {
        $editor                                = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']    = 'pages';
        $editor->groupData['pagetypes_select'] = '1';

        $result = $this->tool->execute(
            ['parent' => self::PARENT_CLOSED, 'title' => 'Sneaky'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(3, $this->pageCount(), 'nothing may have been created');
    }

    #[Test]
    public function anEditorCreatesUnderAPageTheyMayCreateUnder(): void
    {
        $editor                                = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']    = 'pages';
        // `pages.doktype` is checked against the group's page-type grant for
        // a non-admin; without it the DataHandler refuses the record, which
        // is real backend behaviour.
        $editor->groupData['pagetypes_select'] = '1';
        // `hidden` is an exclude field. Without this grant the DataHandler
        // DROPS it silently; core's default for a new page happens to be
        // hidden, but an installation may set it otherwise — see the test
        // below, which is the reason this one states the grant.
        $editor->groupData['non_exclude_fields'] = 'pages:hidden';

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => 'By the editor'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->createdPage()['hidden'] ?? 0));
    }

    /**
     * The failure this tool must never leave behind: the DataHandler drops a
     * field the acting user has no "exclude field" grant for, silently and
     * without an errorLog entry. For `hidden` that would mean a machine-drafted
     * page reachable in the site.
     *
     * Core's own default for a new page is `hidden = 1`, so on a stock
     * installation the dropped field changes nothing. An installation that
     * wants new pages visible sets `TCAdefaults.pages.hidden = 0` in page
     * TSconfig — that is the case this test builds, and the one the read-back
     * exists for: it catches the visible page and the page is taken back
     * again, so the refusal is the whole outcome rather than half of one.
     */
    #[Test]
    public function aPageThatCouldNotBeHiddenIsDeletedAgain(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCAdefaults.pages.hidden = 0'],
            ['uid' => self::PARENT_OPEN],
        );

        $editor                                = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']    = 'pages';
        $editor->groupData['pagetypes_select'] = '1';
        // Deliberately WITHOUT `pages:hidden`.
        $editor->groupData['non_exclude_fields'] = 'pages:nav_title';

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => 'Would have been visible'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('pages:hidden', $result->content);

        // Nothing undeleted is left besides the three fixture pages.
        self::assertSame(3, $this->undeletedPageCount());
    }

    #[Test]
    public function anAnchorUnderAnotherParentIsRefusedAndNothingIsCreated(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_CLOSED, 'title' => 'Misplaced', 'after_page_uid' => self::EXISTING_SUBPAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not a subpage of page [1]', $result->content);
        self::assertSame(3, $this->pageCount());
    }

    #[Test]
    public function thePreviewShowsTheWholeDraftAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['parent' => self::PARENT_OPEN, 'title' => 'Proposed', 'after_page_uid' => self::EXISTING_SUBPAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(6, $lines);
        self::assertStringContainsString('New page under page [2] "Open"', $lines[0]);
        self::assertStringContainsString('"Proposed"', $lines[1]);
        self::assertStringContainsString('same as the title', $lines[2]);
        self::assertStringContainsString('directly after page [20] "Already there"', $lines[3]);
        self::assertStringContainsString('standard page', $lines[4]);
        self::assertStringContainsString('hidden', $lines[5]);

        self::assertSame(3, $this->pageCount(), 'a preview must not create anything');
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);

        $arguments = ['parent' => self::PARENT_CLOSED, 'title' => 'x'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * The one page this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdPage(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->notIn(
                'uid',
                $queryBuilder->createNamedParameter([self::PARENT_CLOSED, self::PARENT_OPEN, self::EXISTING_SUBPAGE], Connection::PARAM_INT_ARRAY),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one page must have been created');

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    private function pageCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Pages that are not flagged deleted — what an editor would still see.
     */
    private function undeletedPageCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<int>
     */
    private function sysLogUserIdsFor(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
