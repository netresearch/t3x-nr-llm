<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the fourth writing tool — the first that creates a record
 * (ADR-146), against a real database and the real
 * {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 *
 * The assertion this file exists for is that the element is HIDDEN. Everything
 * else about the tool is a narrowing of what an editor could do by hand; the
 * hidden state is the one property that makes a machine-drafted element safe,
 * and it must hold without any argument asking for it.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** A page only the admin may edit content on. */
    private const PAGE_CLOSED = 1;

    /** A page every backend user may edit content on. */
    private const PAGE_OPEN = 2;

    private const EXISTING_ELEMENT = 20;

    private CreateContentElementDraftTool $tool;

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
        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 2,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::EXISTING_ELEMENT, 'pid' => self::PAGE_OPEN, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Already there', 'sys_language_uid' => 0,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateContentElementDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theCreatedElementIsHiddenEvenThoughNothingAskedForIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Drafted headline', 'bodytext' => '<p>Body.</p>'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden text element', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);

        $row = $this->createdElement();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted element must never be visible');
        self::assertSame(self::PAGE_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame('text', $row['CType'] ?? null);
        self::assertSame('Drafted headline', $row['header'] ?? null);
        self::assertSame(0, (int)($row['colPos'] ?? -1));
        self::assertSame(0, (int)($row['sys_language_uid'] ?? -1));
    }

    #[Test]
    public function theBodyIsOptionalAndTheColumnIsHonoured(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'header', 'header' => 'Just a headline', 'column' => 2],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdElement();
        self::assertSame('header', $row['CType'] ?? null);
        self::assertSame(2, (int)($row['colPos'] ?? -1));

        $body = $row['bodytext'] ?? '';
        self::assertIsString($body);
        self::assertSame('', $body);
    }

    #[Test]
    public function anAnchorPlacesTheElementAfterIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'              => self::PAGE_OPEN,
                'type'              => 'text',
                'header'            => 'After the existing one',
                'after_content_uid' => self::EXISTING_ELEMENT,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $created = $this->createdElement();
        self::assertSame(self::PAGE_OPEN, (int)($created['pid'] ?? 0), 'a negative pid must resolve to the page');
        self::assertGreaterThan(
            (int)$this->elementRow(self::EXISTING_ELEMENT)['sorting'],
            (int)($created['sorting'] ?? 0),
            'the new element must sort after its anchor',
        );
    }

    #[Test]
    public function theSysLogEntryNamesTheActingUser(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Logged'],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($result->isError, $result->content);

        $created = $this->createdElement();
        self::assertSame(
            [1],
            array_values(array_unique($this->sysLogUserIdsFor((int)($created['uid'] ?? 0)))),
        );
    }

    #[Test]
    public function anEditorMayNotCreateOnAPageTheyMayNotEdit(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        $result = $this->tool->execute(
            ['page' => self::PAGE_CLOSED, 'type' => 'text', 'header' => 'Sneaky'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(1, $this->elementCount(), 'nothing may have been created');
    }

    #[Test]
    public function anEditorCreatesOnAPageTheyMayEdit(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';
        // `tt_content.CType` is an `explicitAllow` field; without the grant the
        // DataHandler refuses the record, which is real backend behaviour.
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';
        // `hidden` is an exclude field. Without this grant the DataHandler
        // DROPS it silently and the element would be created visible — see
        // the test below, which is the reason this one has to state the grant.
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden';

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'By the editor'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->createdElement()['hidden'] ?? 0));
    }

    /**
     * The failure this tool must never leave behind: the DataHandler drops a
     * field the acting user has no "exclude field" grant for, silently and
     * without an errorLog entry. For `hidden` that would mean a machine-drafted
     * element live on the page.
     *
     * The read-back catches it and the element is taken back again, so the
     * refusal is the whole outcome rather than half of one.
     */
    #[Test]
    public function anElementThatCouldNotBeHiddenIsDeletedAgain(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';
        // Deliberately WITHOUT `tt_content:hidden`.
        $editor->groupData['non_exclude_fields'] = 'tt_content:header';

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Would have been visible'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('tt_content:hidden', $result->content);

        // Nothing undeleted is left on the page besides the fixture element.
        self::assertSame(1, $this->undeletedElementCount());
    }

    #[Test]
    public function anAnchorOnAnotherPageIsRefusedAndNothingIsCreated(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'              => self::PAGE_CLOSED,
                'type'              => 'text',
                'header'            => 'Misplaced',
                'after_content_uid' => self::EXISTING_ELEMENT,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not on page [1]', $result->content);
        self::assertSame(1, $this->elementCount());
    }

    #[Test]
    public function thePreviewShowsTheWholeDraftAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Proposed', 'bodytext' => 'Some body text.'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(5, $lines);
        self::assertStringContainsString('New text element on page [2] "Open"', $lines[0]);
        self::assertStringContainsString('"Proposed"', $lines[1]);
        self::assertStringContainsString('Some body text.', $lines[2]);
        self::assertStringContainsString('first in the column', $lines[3]);
        self::assertStringContainsString('hidden', $lines[4]);

        self::assertSame(1, $this->elementCount(), 'a preview must not create anything');
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);

        $arguments = ['page' => self::PAGE_CLOSED, 'type' => 'text', 'header' => 'x'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * The one element this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdElement(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter(self::EXISTING_ELEMENT, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one element must have been created');

        return $rows[0];
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

        self::assertIsArray($row);

        return $row;
    }

    private function elementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Elements that are not flagged deleted — what an editor would still see.
     */
    private function undeletedElementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
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
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
