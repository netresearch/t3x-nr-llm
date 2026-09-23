<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
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
 * The write path of `copy_record` against a real database and the real
 * DataHandler (ADR-198, ADR-199): the copy lands where asked, is hidden
 * whatever the user's preferences say, and a page comes without its subpages.
 */
#[CoversClass(CopyRecordTool::class)]
final class CopyRecordToolTest extends AbstractFunctionalTestCase
{
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const SITE_ROOT = 1;

    private const SOURCE_PAGE = 2;

    private const TARGET_PAGE = 3;

    private const CLOSED_PAGE = 4;

    private const PAGE_TO_COPY = 5;

    private const SUBPAGE = 7;

    private const ELEMENT = 20;

    private const TRANSLATION = 21;

    private const ANCHOR = 22;

    private const FREE_MODE = 23;

    private const CONNECTED_ON_TARGET = 24;

    private const ELEMENT_ON_PAGE_TO_COPY = 25;

    private CopyRecordTool $tool;

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
            [self::SOURCE_PAGE, self::SITE_ROOT, 'Source', Permission::ALL, 0],
            [self::TARGET_PAGE, self::SITE_ROOT, 'Target', Permission::ALL, 0],
            [self::CLOSED_PAGE, self::SITE_ROOT, 'Closed', Permission::ALL & ~Permission::CONTENT_EDIT, 0],
            [self::PAGE_TO_COPY, self::SOURCE_PAGE, 'Template page', Permission::ALL, 0],
            [self::SUBPAGE, self::PAGE_TO_COPY, 'Template child', Permission::ALL, 0],
        ] as [$uid, $pid, $title, $everybody, $siteRoot]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid,
                'is_siteroot' => $siteRoot, 'sorting' => $uid * 256,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, self::SOURCE_PAGE, 0, 'Original', 0, 0],
            [self::TRANSLATION, self::SOURCE_PAGE, 0, 'Original (de)', 1, self::ELEMENT],
            [self::ANCHOR, self::TARGET_PAGE, 2, 'Anchor', 0, 0],
            [self::FREE_MODE, self::SOURCE_PAGE, 0, 'Frei', 1, 0],
            [self::CONNECTED_ON_TARGET, self::TARGET_PAGE, 2, 'Anker (de)', 1, self::ANCHOR],
            [self::ELEMENT_ON_PAGE_TO_COPY, self::PAGE_TO_COPY, 0, 'On the template', 0, 0],
        ] as [$uid, $pid, $colPos, $header, $language, $parent]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'colPos' => $colPos, 'CType' => 'text', 'header' => $header,
                'sys_language_uid' => $language, 'l18n_parent' => $parent, 'sorting' => $uid * 256,
            ]);
        }

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1']);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CopyRecordTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminCopiesAnElementAndTheCopyIsHidden(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE, 'column' => 1],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        $copyUid = (int)$result->writeTarget?->uid;
        self::assertGreaterThan(self::ELEMENT_ON_PAGE_TO_COPY, $copyUid);

        $copy = $this->row('tt_content', $copyUid);
        self::assertSame(self::TARGET_PAGE, (int)($copy['pid'] ?? 0));
        self::assertSame(1, (int)($copy['colPos'] ?? -1));
        self::assertSame(1, (int)($copy['hidden'] ?? 0));
        // Core copies a translation only into a site that carries its
        // language; this instance has no site, so none comes along — and the
        // answer does not claim one.
        self::assertSame(0, $this->rowCount('tt_content', ['l18n_parent' => $copyUid]));
        self::assertStringNotContainsString('translation', $result->content);
        // The source is untouched.
        self::assertSame(0, (int)($this->row('tt_content', self::ELEMENT)['hidden'] ?? 1));
    }

    #[Test]
    public function theCopyIsHiddenEvenWhereTheUserSwitchedHidingOff(): void
    {
        $admin                       = $this->setUpBackendUser(1);
        $admin->uc['neverHideAtCopy'] = 1;

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->row('tt_content', (int)$result->writeTarget?->uid)['hidden'] ?? 0));
    }

    #[Test]
    public function aCopyThatStaysVisibleIsTakenBackAndTheAnswerSaysSo(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$keepVisible = true;
        $before = $this->rowCount('tt_content', ['pid' => self::TARGET_PAGE]);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('it is not hidden', $result->content);
        self::assertStringContainsString('The copy was deleted again.', $result->content);
        self::assertSame($before, $this->rowCount('tt_content', ['pid' => self::TARGET_PAGE]));
    }

    #[Test]
    public function anAnchorLendsItsColumn(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE, 'after_uid' => self::ANCHOR],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(2, (int)($this->row('tt_content', (int)$result->writeTarget?->uid)['colPos'] ?? -1));
    }

    #[Test]
    public function anEditorWithTheGrantsCopiesToAPageTheyMayEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:hidden,tt_content:colPos')),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->row('tt_content', (int)$result->writeTarget?->uid)['hidden'] ?? 0));
    }

    #[Test]
    public function anEditorWithoutTheHiddenGrantIsRefusedBeforeTheCopy(): void
    {
        $before = $this->rowCount('tt_content', []);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:colPos')),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('tt_content:hidden, which the tool sets on the copy', $result->content);
        self::assertSame($before, $this->rowCount('tt_content', []));
    }

    #[Test]
    public function anEditorMayNotCopyOntoAPageTheyMayNotEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::CLOSED_PAGE],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:hidden,tt_content:colPos')),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(0, $this->rowCount('tt_content', ['pid' => self::CLOSED_PAGE]));
    }

    #[Test]
    public function aConnectedTranslationIsRefusedAndItsDefaultLanguageRecordNamed(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::TRANSLATION, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('Copy tt_content [20] instead', $result->content);
    }

    #[Test]
    public function aStandaloneElementIsNotCopiedBesideConnectedTranslations(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::FREE_MODE, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('Inconsistent content detected', $result->content);
        self::assertSame(1, $this->rowCount('tt_content', ['pid' => self::TARGET_PAGE, 'sys_language_uid' => 1]));
    }

    #[Test]
    public function aPageIsCopiedHiddenWithItsContentButWithoutItsSubpages(): void
    {
        $admin                  = $this->setUpBackendUser(1);
        $admin->uc['copyLevels'] = 5;

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::PAGE_TO_COPY, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        $copyUid = (int)$result->writeTarget?->uid;
        $copy    = $this->row('pages', $copyUid);
        self::assertSame(self::TARGET_PAGE, (int)($copy['pid'] ?? 0));
        self::assertSame(1, (int)($copy['hidden'] ?? 0));
        self::assertSame(1, $this->rowCount('tt_content', ['pid' => $copyUid]));
        self::assertSame(0, $this->rowCount('pages', ['pid' => $copyUid]), 'the subpage must not be copied along');
        // The preference is the user's and is handed back as it was.
        self::assertSame(5, $admin->uc['copyLevels']);
    }

    #[Test]
    public function aSiteRootIsNotCopied(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::SITE_ROOT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is a site root', $result->content);
    }

    #[Test]
    public function thePreviewNamesTheTargetAndTheHiddenCopyAndCopiesNothing(): void
    {
        $before = $this->rowCount('tt_content', []);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE, 'after_uid' => self::ANCHOR],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertSame([
            'Copy tt_content [20] "Original", language 0',
            'to: page [3] "Target", column 2, directly after element [22] "Anchor"',
            'with its 1 translation(s); if the target page lacks one of their languages, the copy fails and is taken back',
            'visibility: the copy and every copied translation are hidden — a human must unhide them before anyone sees them',
        ], $lines);
        self::assertSame($before, $this->rowCount('tt_content', []));
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::CLOSED_PAGE];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor('tt_content:hidden,tt_content:colPos')));
    }

    private function editor(string $nonExcludeFields): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages,tt_content';
        $editor->groupData['non_exclude_fields'] = $nonExcludeFields;
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        return $editor;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('%s:%d must exist', $table, $uid));

        return $row;
    }

    /**
     * Undeleted rows matching every given column value.
     *
     * @param array<string, int> $where
     */
    private function rowCount(string $table, array $where): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [$queryBuilder->expr()->eq('deleted', 0)];
        foreach ($where as $column => $value) {
            $constraints[] = $queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT));
        }

        return (int)$queryBuilder->count('uid')->from($table)->where(...$constraints)->executeQuery()->fetchOne();
    }
}
