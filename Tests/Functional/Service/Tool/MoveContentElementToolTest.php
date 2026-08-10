<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the third writing tool, against a real database and the
 * real {@see \TYPO3\CMS\Core\DataHandling\DataHandler} (ADR-146).
 *
 * Argument validation is unit-tested; everything here needs a permission
 * surface that only real `be_users` rows plus `fetchGroupData()` produce — and
 * in particular the rule that separates this tool from the first two: BOTH the
 * source and the target page are authorised.
 */
#[CoversClass(MoveContentElementTool::class)]
final class MoveContentElementToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** A page only the admin may edit content on. */
    private const PAGE_CLOSED = 1;

    /** A page every backend user may edit content on. */
    private const PAGE_OPEN = 2;

    /** A second open page, so a move between two permitted pages is possible. */
    private const PAGE_OPEN_TWO = 3;

    private const ELEMENT_ON_CLOSED = 20;

    private const ELEMENT_ON_OPEN = 21;

    private const ANCHOR_ON_OPEN_TWO = 22;

    private MoveContentElementTool $tool;

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
            'uid' => self::PAGE_CLOSED, 'pid' => 0, 'title' => 'Closed', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        foreach ([self::PAGE_OPEN => 'Open', self::PAGE_OPEN_TWO => 'Open two'] as $uid => $title) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid,
                'sorting' => $uid,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => self::ELEMENT_ON_CLOSED, 'pid' => self::PAGE_CLOSED, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'On the closed page', 'sys_language_uid' => 0,
        ]);
        $content->insert('tt_content', [
            'uid' => self::ELEMENT_ON_OPEN, 'pid' => self::PAGE_OPEN, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Movable', 'sys_language_uid' => 0,
        ]);
        $content->insert('tt_content', [
            'uid' => self::ANCHOR_ON_OPEN_TWO, 'pid' => self::PAGE_OPEN_TWO, 'colPos' => 3, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Anchor', 'sys_language_uid' => 0,
        ]);

        // `calcPerms()` returns nothing for a user in no group or for a page
        // outside the web mounts, whatever the perms_* bits say.
        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2,3',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // tool refuses to write without it.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new MoveContentElementTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminMovesAnElementToAnotherPageAndColumn(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_CLOSED, 'column' => 2],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Moved content element [21]', $result->content);

        $row = $this->elementRow(self::ELEMENT_ON_OPEN);
        self::assertSame(self::PAGE_CLOSED, (int)($row['pid'] ?? 0));
        self::assertSame(2, (int)($row['colPos'] ?? -1));
        // The move changes the place and nothing else.
        self::assertSame('Movable', $row['header'] ?? null);
        self::assertSame('text', $row['CType'] ?? null);
    }

    #[Test]
    public function theColumnIsKeptWhenNoneIsNamed(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_OPEN_TWO],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, (int)($this->elementRow(self::ELEMENT_ON_OPEN)['colPos'] ?? -1));
    }

    #[Test]
    public function anAnchorLendsItsColumnWhenNoneIsNamed(): void
    {
        // The anchor sits in column 3; "after that element" must mean beside it
        // rather than in a column of its own.
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'uid'               => self::ELEMENT_ON_OPEN,
                'target_page'       => self::PAGE_OPEN_TWO,
                'after_content_uid' => self::ANCHOR_ON_OPEN_TWO,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->elementRow(self::ELEMENT_ON_OPEN);
        self::assertSame(self::PAGE_OPEN_TWO, (int)($row['pid'] ?? 0));
        self::assertSame(3, (int)($row['colPos'] ?? -1));
    }

    #[Test]
    public function theSysLogEntryNamesTheActingUser(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_OPEN_TWO],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($result->isError, $result->content);

        self::assertSame([1], array_values(array_unique($this->sysLogUserIdsFor(self::ELEMENT_ON_OPEN))));
    }

    /**
     * The rule that separates this tool from the first two: moving an element
     * OUT of a page edits that page's content, so the SOURCE page needs the
     * grant as much as the target.
     */
    #[Test]
    public function anEditorMayNotMoveAnElementOffAPageTheyMayNotEdit(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_CLOSED, 'target_page' => self::PAGE_OPEN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
        // Nothing moved.
        self::assertSame(self::PAGE_CLOSED, (int)($this->elementRow(self::ELEMENT_ON_CLOSED)['pid'] ?? 0));
    }

    #[Test]
    public function anEditorMayNotMoveAnElementOntoAPageTheyMayNotEdit(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_CLOSED],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
        self::assertSame(self::PAGE_OPEN, (int)($this->elementRow(self::ELEMENT_ON_OPEN)['pid'] ?? 0));
    }

    #[Test]
    public function anEditorMovesBetweenTwoPagesTheyMayEdit(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['non_exclude_fields'] = 'tt_content:colPos';
        // `tt_content.CType` is an `explicitAllow` field, so the DataHandler
        // refuses to touch a record of a type the user was never granted —
        // even a move, which does not change the type. That refusal is real
        // backend behaviour and the tool surfaces it; the editor in this test
        // is given the grant an editor of text elements would have.
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        $result = $this->tool->execute(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_OPEN_TWO, 'column' => 1],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->elementRow(self::ELEMENT_ON_OPEN);
        self::assertSame(self::PAGE_OPEN_TWO, (int)($row['pid'] ?? 0));
        self::assertSame(1, (int)($row['colPos'] ?? -1));
    }

    #[Test]
    public function anAnchorOnAnotherPageIsRefusedAndNamed(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'uid'               => self::ELEMENT_ON_OPEN,
                'target_page'       => self::PAGE_OPEN_TWO,
                'after_content_uid' => self::ELEMENT_ON_CLOSED,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not on page [3]', $result->content);
        // Refused whole: the element stays where it was rather than landing
        // "somewhere" on the target page.
        self::assertSame(self::PAGE_OPEN, (int)($this->elementRow(self::ELEMENT_ON_OPEN)['pid'] ?? 0));
    }

    #[Test]
    public function aMissingElementIsRefusedInTheSameWordsAsAForbiddenOne(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => 987654, 'target_page' => self::PAGE_OPEN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewNamesBothSidesAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['uid' => self::ELEMENT_ON_OPEN, 'target_page' => self::PAGE_OPEN_TWO, 'column' => 4],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(3, $lines);
        self::assertStringContainsString('Movable', $lines[0]);
        self::assertStringContainsString('from: page [2] "Open", column 0', $lines[1]);
        self::assertStringContainsString('to: page [3] "Open two", column 4', $lines[2]);

        // A preview is a read. The row must be exactly as it was.
        $row = $this->elementRow(self::ELEMENT_ON_OPEN);
        self::assertSame(self::PAGE_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame(0, (int)($row['colPos'] ?? -1));
    }

    #[Test]
    public function thePreviewRefusesWhatTheWriteWouldRefuse(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';

        self::assertSame(
            ['Content element not found or not permitted.'],
            $this->tool->previewCall(
                ['uid' => self::ELEMENT_ON_CLOSED, 'target_page' => self::PAGE_OPEN],
                ToolExecutionContext::fromBackendUser($editor),
            ),
        );
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';

        $arguments = ['uid' => self::ELEMENT_ON_CLOSED, 'target_page' => self::PAGE_OPEN];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * @return array<string, mixed>
     */
    private function elementRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('content element %d must exist', $uid));

        return $row;
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
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
