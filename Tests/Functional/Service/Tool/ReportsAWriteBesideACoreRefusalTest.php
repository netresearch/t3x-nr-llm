<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\EventListener\MarkAiWrittenRecord;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * A write that lands while TYPO3 refuses a SIDE write of the same DataHandler
 * run is reported as written, with TYPO3's complaint passed on — and a write
 * that did not land stays a refusal naming that complaint (ADR-198).
 *
 * The side write is what an installation's DataHandler hook does when it
 * writes another page along with the record being saved: here a page the
 * editor may not edit, so core logs "Attempt to modify record pages:170
 * without permission or non-existing page" into the run's error log, exactly
 * as an editor saving the page in the backend form sees it beside "saved".
 */
#[CoversClass(UpdatePageMetadataTool::class)]
final class ReportsAWriteBesideACoreRefusalTest extends AbstractFunctionalTestCase
{
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const PAGE = 1;

    /** A page in the editor's mount that the editor may not edit. */
    private const FORBIDDEN_PAGE = 170;

    private const TARGET_PAGE = 2;

    private const ELEMENT = 10;

    private const SECOND_ELEMENT = 11;

    private const REFERENCE = 20;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $this->connectionPool->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Demo Project', 'slug' => '/', 'doktype' => 1,
            'description'   => 'Live description',
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid'           => self::FORBIDDEN_PAGE, 'pid' => 0, 'title' => 'Closed to the editor', 'slug' => '/other', 'doktype' => 1,
            'sorting'       => 2,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);

        $pages->insert('pages', [
            'uid'           => self::TARGET_PAGE, 'pid' => self::PAGE, 'title' => 'Target', 'slug' => '/target', 'doktype' => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::PAGE, 'colPos' => 0, 'CType' => 'textmedia', 'header' => 'An element', 'image' => 1, 'sorting' => 256,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::SECOND_ELEMENT, 'pid' => self::PAGE, 'colPos' => 0, 'CType' => 'text', 'header' => 'A second element', 'sorting' => 512,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'  => 1, 'pid' => 0, 'storage' => 1, 'type' => 2, 'mime_type' => 'image/png',
            'name' => 'logo.png', 'identifier' => '/logo.png', 'size' => 2048,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'        => self::REFERENCE, 'pid' => self::PAGE, 'uid_local' => 1, 'uid_foreign' => self::ELEMENT,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => 'Live alt',
        ]);

        // Core grants page permissions only to a user in at least one group.
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 9, 'pid' => 0, 'title' => 'Editors']);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$alsoWritePage = self::FORBIDDEN_PAGE;
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aPageDescriptionThatLandsBesideARefusedSideWriteIsReportedAsWrittenInLive(): void
    {
        $result = $this->tool('update_page_metadata')->execute(
            ['uid' => self::PAGE, 'description' => 'New description'],
            ToolExecutionContext::fromBackendUser($this->editorIn(0)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringStartsWith('Updated page [1]: description. TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('pages:' . self::FORBIDDEN_PAGE, $result->content, 'positive control: core refused the side write');
        self::assertEquals(new RecordReference('pages', self::PAGE), $result->writeTarget);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame('New description', $this->column('pages', self::PAGE, 'description'));
        self::assertSame('Closed to the editor', $this->column('pages', self::FORBIDDEN_PAGE, 'title'));
    }

    /**
     * The case seen on the demo: a non-admin editor in the draft workspace.
     */
    #[Test]
    public function aPageDescriptionThatLandsBesideARefusedSideWriteIsReportedAsADraftInTheWorkspace(): void
    {
        $editor  = $this->editorIn(self::DRAFT);
        $context = ToolExecutionContext::fromBackendUser($editor);
        $tool    = $this->tool('update_page_metadata');
        $call    = ['uid' => self::PAGE, 'description' => 'Draft description'];
        self::assertStringContainsString('Workspace: “Entwurf”', implode("\n", $tool->previewCall($call, $context)));

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringStartsWith('Updated page [1]: description. Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('pages:' . self::FORBIDDEN_PAGE, $result->content, 'positive control: core refused the side write');
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);

        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'), 'live changed');
        $version = $this->versionOf('pages', self::PAGE);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Draft description', $version['description']);
        self::assertSame(0, (int) $version['t3ver_state']);
        self::assertSame(0, $this->versionCountOf('pages', self::FORBIDDEN_PAGE), 'the side write made a draft');

        // The provenance mark goes on the version, not on the live page.
        $this->markWritten($result, self::DRAFT);
        self::assertSame(1, (int) ($this->versionOf('pages', self::PAGE)['tx_nrllm_ai_written'] ?? 0));
        self::assertSame(0, (int) $this->column('pages', self::PAGE, 'tx_nrllm_ai_written'));
    }

    /**
     * Where TYPO3 refuses the asked write as well, nothing took: the answer
     * stays a failure, names what did not take, and passes TYPO3's message
     * on.
     */
    #[Test]
    public function aWriteRefusedAlongWithTheSideWriteStaysARefusalNamingTypo3sMessage(): void
    {
        $editor                             = $this->editorIn(0);
        $editor->groupData['tables_modify'] = 'tt_content';

        $result = $this->tool('update_page_metadata')->execute(
            ['uid' => self::PAGE, 'description' => 'Not allowed'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringStartsWith('The update did not take on page [1] for: description.', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('Attempt to modify table "pages" without permission', $result->content);
        self::assertNull($result->writeTarget);
        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'));
    }

    /**
     * The other writers, with a hook of the installation that logs a
     * complaint about every record the run writes, or after every command,
     * and carries on: each reads back, and what landed is reported as
     * written with the complaint passed on.
     */
    #[Test]
    public function theAlternativeTextOfAReferenceBesideAComplaintIsReportedAsADraft(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage         = null;
        InterferesWithAnUpdateHook::$complainOnEveryRecord = true;

        $result = $this->tool('set_file_reference_alternative_text')->execute(
            ['reference' => self::REFERENCE, 'alternative' => 'Draft alt'],
            $this->adminContextIn(self::DRAFT),
        );

        $this->assertWrittenBesideTheComplaint($result, 'A test hook complains about a record and carries on');
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertSame('Live alt', $this->column('sys_file_reference', self::REFERENCE, 'alternative'));
        self::assertSame('Draft alt', $this->versionOf('sys_file_reference', self::REFERENCE)['alternative'] ?? null);
    }

    #[Test]
    public function aMovedElementBesideAComplaintIsReportedAsMoved(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage     = null;
        InterferesWithAnUpdateHook::$complainOnCommand = true;

        $result = $this->tool('move_content_element')->execute(
            ['uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            $this->adminContextIn(0),
        );

        $this->assertWrittenBesideTheComplaint($result, 'A test hook complains about a command and carries on');
        self::assertSame(self::TARGET_PAGE, (int) $this->column('tt_content', self::ELEMENT, 'pid'));
    }

    /**
     * A reorder on the element's own page and column: page and column are as
     * asked whether core moved it or not, so the read-back compares the
     * position after the anchor too.
     */
    #[Test]
    public function aReorderBesideAComplaintIsReportedAsMoved(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage     = null;
        InterferesWithAnUpdateHook::$complainOnCommand = true;

        $result = $this->tool('move_content_element')->execute(
            ['uid' => self::ELEMENT, 'target_page' => self::PAGE, 'after_content_uid' => self::SECOND_ELEMENT],
            $this->adminContextIn(0),
        );

        $this->assertWrittenBesideTheComplaint($result, 'A test hook complains about a command and carries on');
        self::assertGreaterThan(
            (int) $this->column('tt_content', self::SECOND_ELEMENT, 'sorting'),
            (int) $this->column('tt_content', self::ELEMENT, 'sorting'),
        );
    }

    /**
     * The same reorder, which a hook of the installation vetoes and logs:
     * nothing moved, so the answer is a failure naming the position — before,
     * page and column alone would have read back as moved.
     */
    #[Test]
    public function aReorderCoreDidNotPerformIsAFailure(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage     = null;
        InterferesWithAnUpdateHook::$complainOnCommand = true;
        InterferesWithAnUpdateHook::$keepInPlace       = 'tt_content:' . self::ELEMENT;

        $result = $this->tool('move_content_element')->execute(
            ['uid' => self::ELEMENT, 'target_page' => self::PAGE, 'after_content_uid' => self::SECOND_ELEMENT],
            $this->adminContextIn(0),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringStartsWith('The move did not take: content element [10] is not on page [1] in column 0 directly after element [11] afterwards.', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertNull($result->writeTarget);
        self::assertSame(256, (int) $this->column('tt_content', self::ELEMENT, 'sorting'));
    }

    /**
     * Before, the element was created in the workspace and the answer said
     * "refused": a draft nobody was told about.
     */
    #[Test]
    public function aContentElementCreatedBesideAComplaintIsReportedAsCreated(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage         = null;
        InterferesWithAnUpdateHook::$complainOnEveryRecord = true;
        $before                                            = $this->rowCount('tt_content');

        $result = $this->tool('create_content_element_draft')->execute(
            ['page' => self::PAGE, 'type' => 'text', 'header' => 'Beside a complaint'],
            $this->adminContextIn(self::DRAFT),
        );

        $this->assertWrittenBesideTheComplaint($result, 'A test hook complains about a record and carries on');
        self::assertSame($before + 1, $this->rowCount('tt_content'));
        $uid = $result->writeTarget->uid ?? 0;
        self::assertSame(self::DRAFT, (int) $this->column('tt_content', $uid, 't3ver_wsid'));
        self::assertSame('Beside a complaint', $this->column('tt_content', $uid, 'header'));
    }

    #[Test]
    public function aPageCreatedBesideAComplaintIsReportedAsCreated(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage         = null;
        InterferesWithAnUpdateHook::$complainOnEveryRecord = true;

        $result = $this->tool('create_page_draft')->execute(
            ['parent' => self::PAGE, 'title' => 'Beside a complaint'],
            $this->adminContextIn(0),
        );

        $this->assertWrittenBesideTheComplaint($result, 'A test hook complains about a record and carries on');
        $uid = $result->writeTarget->uid ?? 0;
        self::assertSame('Beside a complaint', $this->column('pages', $uid, 'title'));
        self::assertSame(1, (int) $this->column('pages', $uid, 'hidden'));
    }

    /**
     * Before, the copy was taken back although it landed hidden where it
     * was asked. What core copies along is not read back, so beside a
     * complaint the copy is kept — hidden — and reported as written in part,
     * to be checked.
     */
    #[Test]
    public function aCopyBesideAComplaintIsKeptAndReportedForAReview(): void
    {
        InterferesWithAnUpdateHook::$alsoWritePage     = null;
        InterferesWithAnUpdateHook::$complainOnCommand = true;

        $result = $this->tool('copy_record')->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            $this->adminContextIn(0),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Check the copy before using it', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('A test hook complains about a command and carries on', $result->content);
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
        $uid = $result->writeTarget->uid;
        self::assertSame(self::TARGET_PAGE, (int) $this->column('tt_content', $uid, 'pid'));
        self::assertSame(1, (int) $this->column('tt_content', $uid, 'hidden'));
    }

    private function assertWrittenBesideTheComplaint(ToolResult $result, string $complaint): void
    {
        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString($complaint, $result->content, 'positive control: the hook complained');
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
    }

    private function adminContextIn(int $workspace): ToolExecutionContext
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $admin->workspace, 'positive control: the user is in the workspace');

        return ToolExecutionContext::fromBackendUser($admin);
    }

    private function rowCount(string $table): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)->executeQuery()->fetchOne();
    }

    /**
     * A non-admin editor with the page in their mount and the grants the
     * backend form needs, in the given workspace.
     */
    private function editorIn(int $workspace): BackendUserAuthentication
    {
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', [
            'usergroup'    => '9',
            'workspace_id' => $workspace,
            // Live editing allowed: a member of a draft workspace without it
            // is put into that workspace whatever `workspace_id` says.
            'workspace_perms' => 1,
            'db_mountpoints'  => self::PAGE . ',' . self::FORBIDDEN_PAGE,
        ], ['uid' => 2]);
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertSame($workspace, $editor->workspace, 'positive control: the editor works in the workspace');
        $editor->groupData['tables_modify']      = 'pages,tt_content,sys_file_reference';
        $editor->groupData['tables_select']      = 'pages,tt_content,sys_file_reference';
        $editor->groupData['non_exclude_fields'] = 'pages:description,pages:title';
        // Core copies the page into its version through a new record, which
        // asks the page type grant a live edit does not ask.
        $editor->groupData['pagetypes_select'] = '1';

        return $editor;
    }

    private function markWritten(ToolResult $result, int $workspace): void
    {
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertNotNull($result->writeKind);
        $listener = $this->get(MarkAiWrittenRecord::class);
        self::assertInstanceOf(MarkAiWrittenRecord::class, $listener);
        $listener(new AfterAiRecordWrittenEvent('run-1', $result->writeTarget, $result->writeKind, $workspace));
    }

    /**
     * @return ToolInterface&ToolPreviewInterface
     */
    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($name, true);

        return $tool;
    }

    private function column(string $table, int $uid, string $column): mixed
    {
        $row = $this->row($table, ['uid' => $uid]);
        self::assertNotNull($row, sprintf('%s [%d] is gone', $table, $uid));

        return $row[$column] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionOf(string $table, int $uid): ?array
    {
        return $this->row($table, ['t3ver_oid' => $uid, 't3ver_wsid' => self::DRAFT]);
    }

    private function versionCountOf(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)
            ->where($queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    /**
     * @param array<string, int> $where
     *
     * @return array<string, mixed>|null
     */
    private function row(string $table, array $where): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from($table);
        foreach ($where as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)));
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
