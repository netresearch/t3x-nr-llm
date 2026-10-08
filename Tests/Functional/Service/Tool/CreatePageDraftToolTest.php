<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewComparator;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
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
    use AssertsPreviewHeadingTrait;

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

        $this->tool = new CreatePageDraftTool($this->connectionPool, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
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
        $arguments = ['parent' => self::PARENT_OPEN, 'title' => 'Proposed', 'after_page_uid' => self::EXISTING_SUBPAGE];

        // ADR-213: what, where, the new state, the consequence, then the
        // identifiers. No field name, no English in the German lines.
        self::assertSame([
            'Create new page as draft',
            'Location: under “Open”',
            'Title: “Proposed”',
            'Navigation title: same as the page title',
            'Page type: standard page',
            'Language: default language',
            'Position: directly after “Already there”',
            'Visibility: hidden at first',
            'After it is created the page is not publicly visible yet and has no content. It has to be made visible by a person.',
            'Technical details: parent page UID 2, preceding page UID 20',
        ], $this->previewIn('en', $arguments));
        self::assertSame([
            'Neue Seite als Entwurf anlegen',
            'Ort: unter „Open“',
            'Titel: „Proposed“',
            'Navigationstitel: wie der Seitentitel',
            'Seitentyp: Standardseite',
            'Sprache: Standardsprache',
            'Position: direkt nach „Already there“',
            'Sichtbarkeit: zunächst verborgen',
            'Die Seite ist nach dem Anlegen noch nicht öffentlich sichtbar und enthält noch keine Inhalte. Sie muss erst von einer Person sichtbar gemacht werden.',
            'Technische Details: übergeordnete Seite UID 2, vorangehende Seite UID 20',
        ], $this->previewIn('de', $arguments));
        self::assertStartsWithHeading($this->previewIn('en', $arguments), 'en');
        self::assertStartsWithHeading($this->previewIn('de', $arguments), 'de');

        self::assertSame(3, $this->pageCount(), 'a preview must not create anything');
    }

    /**
     * ADR-213 with ADR-184: the lines are compared byte for byte on resume, so
     * the language of whoever happens to be looking — the ambient
     * $GLOBALS['LANG'] of the request that renders or resumes — must not reach
     * them. Only the acting user's `lang` decides.
     */
    #[Test]
    public function thePreviewIgnoresTheLanguageOfTheViewingRequest(): void
    {
        $arguments = ['parent' => self::PARENT_OPEN, 'title' => 'Proposed'];
        $english   = $this->previewIn('en', $arguments);
        $german    = $this->previewIn('de', $arguments);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('de');
        self::assertSame($english, $this->previewIn('en', $arguments));

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        self::assertSame($german, $this->previewIn('de', $arguments));
        self::assertNotSame($english, $german);
    }

    #[Test]
    public function thePreviewShowsAnExplicitNavigationTitleAndTheFirstPosition(): void
    {
        $arguments = ['parent' => self::PARENT_OPEN, 'title' => 'Proposed', 'nav_title' => 'Short'];

        $english = $this->previewIn('en', $arguments);
        self::assertContains('Navigation title: “Short”', $english);
        self::assertContains('Position: first subpage', $english);

        $german = $this->previewIn('de', $arguments);
        self::assertContains('Navigationstitel: „Short“', $german);
        self::assertContains('Position: erste Unterseite', $german);
        self::assertContains('Technische Details: übergeordnete Seite UID 2', $german);
    }

    /**
     * NEXT-167, demo conversation 84: after the first page of that title had
     * been created and approved, "weiter" made the model draft it again, and
     * the second call was approved too. The approver now sees that a sibling
     * with this exact title exists — hidden, as every drafted page is.
     */
    #[Test]
    public function thePreviewWarnsWhenTheParentAlreadyHoldsAPageWithThisTitle(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 10073, 'pid' => self::PARENT_OPEN, 'title' => 'Prof. Dr. Max Mustermann', 'doktype' => 1,
            'hidden' => 1, 'sorting' => 3,
        ]);
        $arguments = ['parent' => self::PARENT_OPEN, 'title' => 'Prof. Dr. Max Mustermann'];

        $english = $this->previewIn('en', $arguments);
        self::assertCount(11, $english);
        self::assertSame(
            'Warning: a hidden page with the same title already exists under this page. Approving creates a second page with that title.',
            $english[9],
        );
        self::assertSame('Technical details: parent page UID 2, existing page with the same title UID 10073', $english[10]);

        $german = $this->previewIn('de', $arguments);
        self::assertSame(
            'Warnung: Unter dieser Seite gibt es bereits eine verborgene Seite mit demselben Titel. Mit der Freigabe entsteht eine zweite Seite mit diesem Titel.',
            $german[9],
        );
        self::assertSame('Technische Details: übergeordnete Seite UID 2, vorhandene Seite mit gleichem Titel UID 10073', $german[10]);

        // A visible twin gets the sentence without "hidden".
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['hidden' => 0], ['uid' => 10073]);
        self::assertSame(
            'Warnung: Unter dieser Seite gibt es bereits eine Seite mit demselben Titel. Mit der Freigabe entsteht eine zweite Seite mit diesem Titel.',
            $this->previewIn('de', $arguments)[9],
        );
    }

    #[Test]
    public function thePreviewDoesNotWarnForADeletedPageATranslationOrAnotherParent(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 30, 'pid' => self::PARENT_OPEN, 'title' => 'Twin', 'doktype' => 1, 'deleted' => 1]);
        $pages->insert('pages', ['uid' => 31, 'pid' => self::PARENT_OPEN, 'title' => 'Twin', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 30]);
        $pages->insert('pages', ['uid' => 32, 'pid' => self::PARENT_CLOSED, 'title' => 'Twin', 'doktype' => 1]);

        $lines = $this->previewIn('en', ['parent' => self::PARENT_OPEN, 'title' => 'Twin']);

        self::assertCount(10, $lines);
        self::assertStringNotContainsString('Warning', implode("\n", $lines));
    }

    /**
     * Being allowed to create pages under a parent is not being allowed to
     * see every page there: a sibling the editor may not show is not named.
     */
    #[Test]
    public function thePreviewDoesNotNameASiblingTheEditorMayNotSee(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 33, 'pid' => self::PARENT_OPEN, 'title' => 'Private twin', 'doktype' => 1, 'sorting' => 4,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $editor                                = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']    = 'pages';
        $editor->groupData['pagetypes_select'] = '1';

        $lines = $this->tool->previewCall(
            ['parent' => self::PARENT_OPEN, 'title' => 'Private twin'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertSame('Create new page as draft', $lines[0] ?? '');
        self::assertStringNotContainsString('33', implode("\n", $lines));
    }

    /**
     * Two runs draft the same page. The first is approved and creates it; the
     * second's preview, shown before that, carried no warning. ADR-184's
     * comparison at approval re-previews the call, now finds the warning, and
     * hands the run back with the fresh preview instead of executing it — so
     * the second approver sees the warning after all.
     */
    #[Test]
    public function aConcurrentDraftOfTheSamePageBouncesTheSecondApprovalWithTheWarning(): void
    {
        $admin     = $this->setUpBackendUser(1);
        $context   = ToolExecutionContext::fromBackendUser($admin);
        $arguments = ['parent' => self::PARENT_OPEN, 'title' => 'Twice drafted'];
        $registry  = new ToolRegistry([$this->tool]);
        $comparator = new ApprovalPreviewComparator($registry);

        $shown = $comparator->bound($this->tool->previewCall($arguments, $context));
        self::assertSame('Create new page as draft', $shown[0] ?? '');
        $suspended = new SuspendedRunState(
            messages: [],
            pendingCalls: [['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'create_page_draft', 'arguments' => $arguments]]],
            iterations: 1,
            promptTokens: 0,
            completionTokens: 0,
            callPreviews: [['index' => 0, 'tool' => 'create_page_draft', 'lines' => $shown, 'failed' => false]],
        );

        // The first run's draft is approved and written.
        self::assertFalse($this->tool->execute($arguments, $context)->isError);

        $bounced = $comparator->compare($suspended, $suspended->toolCalls(), ['create_page_draft'], $context);

        self::assertInstanceOf(SuspendedRunState::class, $bounced);
        self::assertSame([0], $bounced->staleCallIndexes);
        self::assertStringStartsWith('Warning: a hidden page with the same title', $bounced->callPreviews[0]['lines'][9] ?? '');
    }

    /**
     * NEXT-167, demo conversations 80 and 92: an element meant for a freshly
     * created page twice landed on its parent. The result leads with the new
     * uid and names the parent as the page NOT to use.
     */
    #[Test]
    public function theResultLeadsWithTheNewUidAndWarnsOffTheParent(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['parent' => self::PARENT_OPEN, 'title' => self::DRAFTED_TITLE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        $newUid = (int)($this->createdPage()['uid'] ?? 0);
        self::assertStringStartsWith(sprintf('New page uid: %d.', $newUid), $result->content);
        self::assertStringContainsString(
            sprintf('targets page %d, not the parent %d', $newUid, self::PARENT_OPEN),
            $result->content,
        );
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
