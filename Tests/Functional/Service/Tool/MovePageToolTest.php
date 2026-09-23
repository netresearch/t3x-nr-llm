<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\MovePageTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of `move_page` against a real database and the real
 * DataHandler (ADR-198): the page moves with its translations and branch,
 * keeps its slug, and only where core's own move permissions hold.
 */
#[CoversClass(MovePageTool::class)]
final class MovePageToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const SITE_ROOT = 1;

    private const SECTION_A = 2;

    private const SECTION_B = 3;

    private const MOVED = 4;

    private const TRANSLATION = 5;

    private const CHILD = 6;

    private const CLOSED = 7;

    private const SIBLING_IN_B = 8;

    /** A page an editor may edit but not delete: reorder only. */
    private const PINNED = 9;

    private const INVISIBLE = 10;

    private MovePageTool $tool;

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
            [self::SITE_ROOT, 0, 'Root', '/', 0, 0, Permission::ALL, 1],
            [self::SECTION_A, self::SITE_ROOT, 'Section A', '/a', 0, 0, Permission::ALL, 0],
            [self::SECTION_B, self::SITE_ROOT, 'Section B', '/b', 0, 0, Permission::ALL, 0],
            [self::MOVED, self::SECTION_A, 'Moved', '/a/moved', 0, 0, Permission::ALL, 0],
            [self::TRANSLATION, self::SECTION_A, 'Verschoben', '/a/verschoben', 1, self::MOVED, Permission::ALL, 0],
            [self::CHILD, self::MOVED, 'Child', '/a/moved/child', 0, 0, Permission::ALL, 0],
            [self::CLOSED, self::SITE_ROOT, 'Closed', '/closed', 0, 0, Permission::ALL & ~Permission::PAGE_NEW, 0],
            [self::PINNED, self::SECTION_A, 'Pinned', '/a/pinned', 0, 0, Permission::ALL & ~Permission::PAGE_DELETE, 0],
            [self::INVISIBLE, self::SECTION_A, 'Invisible', '/a/invisible', 0, 0, 0, 0],
            [self::SIBLING_IN_B, self::SECTION_B, 'Sibling', '/b/sibling', 0, 0, Permission::ALL, 0],
        ] as [$uid, $pid, $title, $slug, $language, $parent, $everybody, $siteRoot]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'slug' => $slug,
                'sys_language_uid' => $language, 'l10n_parent' => $parent, 'is_siteroot' => $siteRoot,
                'sorting' => $uid * 256,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1']);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new MovePageTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminMovesAPageWithItsTranslationAndBranchAndItKeepsItsSlug(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Moved page [4] "Moved" from under page [2] to under page [3]', $result->content);
        self::assertSame(WriteKind::UPDATED, $result->writeKind);

        $moved = $this->pageRow(self::MOVED);
        self::assertSame(self::SECTION_B, (int)($moved['pid'] ?? 0));
        self::assertSame('/a/moved', $moved['slug'] ?? null, 'core does not regenerate the slug on a move');
        self::assertSame(self::SECTION_B, (int)($this->pageRow(self::TRANSLATION)['pid'] ?? 0));
        self::assertSame(self::MOVED, (int)($this->pageRow(self::CHILD)['pid'] ?? 0));
    }

    #[Test]
    public function aSiblingAnchorGivesTheParent(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'after_page_uid' => self::SIBLING_IN_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::SECTION_B, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
        self::assertGreaterThan(
            (int)($this->pageRow(self::SIBLING_IN_B)['sorting'] ?? 0),
            (int)($this->pageRow(self::MOVED)['sorting'] ?? 0),
        );
    }

    #[Test]
    public function anAnchorUnderAnotherParentIsRefused(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::SECTION_A, 'after_page_uid' => self::SIBLING_IN_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('page [8] is not under page [2]', $result->content);
    }

    #[Test]
    public function aPageDoesNotMoveIntoItsOwnBranch(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::CHILD],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('inside the branch of page [4]', $result->content);
        self::assertSame(self::SECTION_A, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
    }

    #[Test]
    public function aTranslationIsRefusedAndItsDefaultLanguagePageNamed(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TRANSLATION, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('Move page [4] instead', $result->content);
    }

    #[Test]
    public function aPageTranslationIsNoParent(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::SIBLING_IN_B, 'parent' => self::TRANSLATION],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('page [5] is a translation (language 1) of page [4]', $result->content);
        self::assertSame(self::SECTION_B, (int)($this->pageRow(self::SIBLING_IN_B)['pid'] ?? 0));
    }

    #[Test]
    public function thePreviewWarnsWhenThePageMovesIntoAnotherSite(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 11, 'pid' => 0, 'title' => 'Other root', 'doktype' => 1, 'slug' => '/', 'is_siteroot' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $lines = $this->tool->previewCall(
            ['uid' => self::MOVED, 'parent' => 11],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertContains(
            'moves into another site: from the site of root page [1] to the site of root page [11] — its address follows '
            . 'the site it lies in from then on',
            $lines,
        );
    }

    #[Test]
    public function thePreviewNamesASideOutsideEverySite(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 12, 'pid' => 0, 'title' => 'Storage', 'doktype' => 254, 'slug' => '/storage',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $lines = $this->tool->previewCall(
            ['uid' => self::MOVED, 'parent' => 12],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertContains(
            'moves into another site: from the site of root page [1] to outside every site — its address follows the '
            . 'site it lies in from then on',
            $lines,
        );
    }

    #[Test]
    public function aSiteRootIsNotMoved(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::SITE_ROOT, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is a site root', $result->content);
    }

    #[Test]
    public function anEditorMovesAPageBetweenPagesTheyMayUse(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::SECTION_B, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
    }

    #[Test]
    public function anEditorMayNotMoveAPageUnderOneTheyMayNotCreateIn(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::CLOSED],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(self::SECTION_A, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
    }

    #[Test]
    public function anEditorMayNotMoveAPageWhoseTranslationIsInALanguageTheyMayNotEdit(): void
    {
        $editor                                 = $this->editor();
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['uid' => self::MOVED, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('translations in language(s) 1', $result->content);
        self::assertSame(self::SECTION_A, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
    }

    #[Test]
    public function anEditorReordersAPageTheyMayEditButNotDelete(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::PINNED, 'after_page_uid' => self::MOVED],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertGreaterThan(
            (int)($this->pageRow(self::MOVED)['sorting'] ?? 0),
            (int)($this->pageRow(self::PINNED)['sorting'] ?? 0),
        );
    }

    #[Test]
    public function anEditorMayNotMoveAPageToAnotherParentWithoutTheDeletePermission(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::PINNED, 'parent' => self::SECTION_B],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(self::SECTION_A, (int)($this->pageRow(self::PINNED)['pid'] ?? 0));
    }

    #[Test]
    public function aPageTheEditorCannotSeeIsRefusedBeforeAnyRefusalNamesItsSurroundings(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::INVISIBLE, 'parent' => self::SECTION_A, 'after_page_uid' => self::SIBLING_IN_B],
            ToolExecutionContext::fromBackendUser($this->editor()),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewNamesBothEndsAndTheUnchangedSlugAndMovesNothing(): void
    {
        $lines = $this->tool->previewCall(
            ['uid' => self::MOVED, 'after_page_uid' => self::SIBLING_IN_B],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertSame([
            'Page [4] "Moved", with its content, 1 translation(s) and 1 subpage(s):',
            'from: under page [2] "Section A"',
            'to: under page [3] "Section B", directly after page [8] "Sibling"',
            'URL path unchanged: /a/moved (the slug is not regenerated)',
        ], $lines);
        self::assertSame(self::SECTION_A, (int)($this->pageRow(self::MOVED)['pid'] ?? 0));
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['uid' => self::MOVED, 'parent' => self::CLOSED];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor()));
    }

    private function editor(): BackendUserAuthentication
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'pages';

        return $editor;
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
}
