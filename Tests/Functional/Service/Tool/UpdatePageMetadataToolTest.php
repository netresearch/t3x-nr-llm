<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the first writing tool, against a real database and the
 * real {@see \TYPO3\CMS\Core\DataHandling\DataHandler} (ADR-135).
 *
 * Argument validation is unit-tested; everything here needs a permission surface
 * that only a real `be_users` row plus `fetchGroupData()` produces: the acting
 * user's page permissions, the DataHandler's own second enforcement, its
 * `errorLog`, and the `sys_log` row that names who wrote.
 */
#[CoversClass(UpdatePageMetadataTool::class)]
final class UpdatePageMetadataToolTest extends AbstractFunctionalTestCase
{
    /** A page only the admin may edit: owned by uid 1, nothing granted to anyone else. */
    private const PAGE_ADMIN_ONLY = 1;

    /** A page every backend user may edit — the page-permission axis is open here. */
    private const PAGE_OPEN = 2;

    /** A uid no page carries. */
    private const PAGE_MISSING = 987654;

    private UpdatePageMetadataTool $tool;

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
            'uid' => self::PAGE_ADMIN_ONLY, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1, 'description' => 'Old description',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 2, 'description' => 'Old description',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        // `calcPerms()` returns NOTHING for a user in no group and for a page
        // outside the web mounts, whatever the perms_* bits say — so the
        // non-admin needs a group whose DB mount covers BOTH pages. Page 1 is
        // deliberately inside the mount: its denial must come from the
        // permission bits, not from an unreachable mount.
        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        // options = 3: inherit DB and file mounts from the groups.
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // tool refuses to write without it (ADR-135) — so the happy path has to
        // establish it, exactly as a backend request does.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new UpdatePageMetadataTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminWritesTheAllowListedFields(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'New title', 'description' => 'New description'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Updated page [1]', $result->content);

        $row = $this->pageRow(self::PAGE_ADMIN_ONLY);
        self::assertSame('New title', $row['title'] ?? null);
        self::assertSame('New description', $row['description'] ?? null);
        // Untouched columns stay untouched.
        self::assertSame('/', $row['slug'] ?? null);
    }

    #[Test]
    public function theSysLogEntryNamesTheActingUserAndTheRecord(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Logged title'],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($result->isError, $result->content);

        $userIds = $this->sysLogUserIdsFor(self::PAGE_ADMIN_ONLY);
        self::assertNotSame([], $userIds, 'the DataHandler must have written an audit row for the update');
        self::assertSame([1], array_values(array_unique($userIds)));
    }

    /**
     * The write runs under the user in the run's {@see ToolExecutionContext}
     * (ADR-083), not under the ambient one — and `sys_log` records that user.
     */
    #[Test]
    public function theAuditNamesTheContextUserNotTheAmbientOne(): void
    {
        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']     = 'pages';
        $editor->groupData['non_exclude_fields'] = 'pages:description';
        // Ambient user is somebody else entirely.
        $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_OPEN, 'description' => 'Written by the editor'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame([2], array_values(array_unique($this->sysLogUserIdsFor(self::PAGE_OPEN))));
    }

    #[Test]
    public function aNonAdminWithoutEditRightIsRefusedIndistinguishablyFromAMissingPage(): void
    {
        $editor = $this->setUpBackendUser(2);
        $context = ToolExecutionContext::fromBackendUser($editor);

        $denied  = $this->tool->execute(['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Hijacked'], $context);
        $missing = $this->tool->execute(['uid' => self::PAGE_MISSING, 'title' => 'Hijacked'], $context);

        self::assertTrue($denied->isError);
        self::assertTrue($missing->isError);
        // Byte-identical: the model may not learn that page 1 exists.
        self::assertSame($missing->content, $denied->content);
        self::assertSame('Page not found or not permitted.', $denied->content);

        // And nothing was written.
        self::assertSame('Home', $this->pageRow(self::PAGE_ADMIN_ONLY)['title'] ?? null);
        self::assertSame([], $this->sysLogUserIdsFor(self::PAGE_ADMIN_ONLY));
    }

    /**
     * ADR-136: the arguments on the approval card ARE the new values, so the
     * preview's job is the other column — what the write would replace.
     */
    #[Test]
    public function thePreviewShowsTheStoredValueNextToTheProposedOne(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Home', 'description' => 'New description'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame('Page [1] "Home" — 2 field(s):', $lines[0]);
        // A field whose proposed value equals the stored one is named as such —
        // an approver should not have to diff two identical strings by eye.
        self::assertSame('title: unchanged ("Home")', $lines[1]);
        self::assertSame('description: "Old description" → "New description"', $lines[2]);

        // A preview reads; it must not write.
        self::assertSame('Old description', $this->pageRow(self::PAGE_ADMIN_ONLY)['description'] ?? null);
        self::assertSame([], $this->sysLogUserIdsFor(self::PAGE_ADMIN_ONLY));
    }

    /**
     * The preview authorises against the run's EXPLICIT acting user, exactly
     * like the write — otherwise it would be a read-anything oracle wearing the
     * write tool's name.
     */
    #[Test]
    public function thePreviewRefusesAPageTheActingUserMayNotEditWithTheSameNeutralString(): void
    {
        $editor  = $this->setUpBackendUser(2);
        $context = ToolExecutionContext::fromBackendUser($editor);

        $denied  = $this->tool->previewCall(['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Hijacked'], $context);
        $missing = $this->tool->previewCall(['uid' => self::PAGE_MISSING, 'title' => 'Hijacked'], $context);

        self::assertSame(['Page not found or not permitted.'], $denied);
        // Byte-identical, as in execute(): the preview may not confirm that a
        // page exists either.
        self::assertSame($missing, $denied);
    }

    /**
     * ADR-136, read side: the approver is a different person from the run owner
     * and holds a tool-level grant only, so the card asks the tool whether THIS
     * viewer may see the record at all.
     */
    #[Test]
    public function theViewerGateAnswersPerRecordAndNotPerTool(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);

        self::assertTrue($this->tool->mayViewerReadPreview(['uid' => self::PAGE_ADMIN_ONLY], $admin));
        // Same tool, same call, different viewer: the editor holds no edit
        // right on this page, so the lines the run owner produced stay hidden.
        self::assertFalse($this->tool->mayViewerReadPreview(['uid' => self::PAGE_ADMIN_ONLY], $editor));
    }

    #[Test]
    public function theViewerGateRefusesAMissingPageExactlyLikeAForbiddenOne(): void
    {
        $admin = $this->setUpBackendUser(1);

        self::assertFalse($this->tool->mayViewerReadPreview(['uid' => self::PAGE_MISSING], $admin));
        self::assertFalse($this->tool->mayViewerReadPreview(['uid' => 0], $admin));
    }

    /**
     * A call the tool would refuse previews as that refusal. The approver needs
     * to know a release would achieve nothing.
     */
    #[Test]
    public function thePreviewOfARefusableCallIsTheRefusal(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['uid' => self::PAGE_ADMIN_ONLY, 'slug' => '/hijacked'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(1, $lines);
        self::assertStringContainsString('not an editable page metadata field', $lines[0]);
    }

    #[Test]
    public function anUnknownFieldIsRefusedAndNothingIsWritten(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'New title', 'slug' => '/hijacked'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('not an editable page metadata field', $result->content);

        // The whole call is refused, not the unknown half of it: the allow-listed
        // `title` in the same call must not have landed either.
        $row = $this->pageRow(self::PAGE_ADMIN_ONLY);
        self::assertSame('Home', $row['title'] ?? null);
        self::assertSame('/', $row['slug'] ?? null);
    }

    #[Test]
    public function aWorkspaceOtherThanLiveIsRefused(): void
    {
        $admin            = $this->setUpBackendUser(1);
        $admin->workspace = 1;

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Draft title'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
        self::assertSame('Home', $this->pageRow(self::PAGE_ADMIN_ONLY)['title'] ?? null);
    }

    /**
     * The page-permission pre-check passes and the DataHandler still refuses —
     * the editor holds no `tables_modify` grant for `pages`. A non-empty
     * `errorLog` must surface as `isError`, never as a silent success.
     */
    #[Test]
    public function aDataHandlerErrorBecomesAnErrorResult(): void
    {
        $editor = $this->setUpBackendUser(2);
        // No tables_modify grant: page permissions alone do not authorise a write.
        self::assertFalse($editor->check('tables_modify', 'pages'));

        $result = $this->tool->execute(
            ['uid' => self::PAGE_OPEN, 'title' => 'Not allowed'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertSame('Open', $this->pageRow(self::PAGE_OPEN)['title'] ?? null);
    }

    /**
     * The DataHandler drops a field the user lacks the "exclude field" grant for
     * SILENTLY — no exception, no `errorLog` entry. Without the read-back the
     * tool would report a successful write that never happened, and an approver
     * would have signed off on nothing.
     */
    #[Test]
    public function aSilentlyDroppedFieldIsReportedAsAnErrorRatherThanASuccess(): void
    {
        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'pages';
        // Deliberately NOT granting pages:description.
        $editor->groupData['non_exclude_fields'] = '';

        $result = $this->tool->execute(
            ['uid' => self::PAGE_OPEN, 'description' => 'Never stored'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('did not take', $result->content);
        self::assertStringContainsString('description', $result->content);
        self::assertSame('Old description', $this->pageRow(self::PAGE_OPEN)['description'] ?? null);
    }

    /**
     * `pages.title` is `required` in the core TCA, and the DataHandler drops an
     * empty value for it as silently as a missing field grant — no exception, no
     * `errorLog` entry. Without the guard in `collectValues()` this call returns
     * 'The update did not take on page [1] for: title. The acting backend user is
     * most likely missing the field-level ("exclude field") grant for them.' — to
     * an admin, who holds every field grant by definition.
     *
     * The TCA runs live here, so the premise needs no separate assertion: the
     * refusal below can only happen while core declares `pages.title` required.
     */
    #[Test]
    public function anEmptyValueForARequiredFieldIsRefusedWithItsRealReason(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => '   '],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is required and cannot be emptied', $result->content);
        self::assertStringNotContainsString('exclude field', $result->content);

        // Refused before the DataHandler, so nothing was written and nothing logged.
        self::assertSame('Home', $this->pageRow(self::PAGE_ADMIN_ONLY)['title'] ?? null);
        self::assertSame([], $this->sysLogUserIdsFor(self::PAGE_ADMIN_ONLY));
    }

    /**
     * The guard is scoped to required fields on purpose: clearing an optional one
     * is a write the DataHandler performs and the read-back verifies.
     */
    #[Test]
    public function anOptionalFieldMayStillBeCleared(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'description' => ''],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('', $this->pageRow(self::PAGE_ADMIN_ONLY)['description'] ?? null);
    }

    #[Test]
    public function aNonAdminWithEveryGrantWritesSuccessfully(): void
    {
        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages';
        $editor->groupData['non_exclude_fields'] = 'pages:description';

        $result = $this->tool->execute(
            ['uid' => self::PAGE_OPEN, 'description' => 'Editor description'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Editor description', $this->pageRow(self::PAGE_OPEN)['description'] ?? null);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Service account'],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame('Home', $this->pageRow(self::PAGE_ADMIN_ONLY)['title'] ?? null);
    }

    /**
     * The E2 refusal, proven rather than asserted in prose: with no
     * `$GLOBALS['LANG']` the DataHandler's declared prerequisites are not met,
     * and the tool refuses instead of establishing globals it does not own.
     */
    #[Test]
    public function aMissingLanguageServiceRefusesTheWrite(): void
    {
        $admin = $this->setUpBackendUser(1);
        unset($GLOBALS['LANG']);

        $result = $this->tool->execute(
            ['uid' => self::PAGE_ADMIN_ONLY, 'title' => 'Worker title'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('language service', $result->content);
        self::assertSame('Home', $this->pageRow(self::PAGE_ADMIN_ONLY)['title'] ?? null);
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

        self::assertIsArray($row, sprintf('page %d must exist', $uid));

        return $row;
    }

    /**
     * The `sys_log` user ids the DataHandler recorded for a `pages` record.
     *
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
