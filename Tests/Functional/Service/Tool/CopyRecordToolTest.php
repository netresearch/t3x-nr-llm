<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Error;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\FailsLikeAFlashMessageHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
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
    use RegistersTheFailingHookTrait;
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

    /** The German translation of TARGET_PAGE, so core can place a German element there. */
    private const TARGET_PAGE_GERMAN = 9;

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
        $this->unregisterFailingHook();
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

    /**
     * Core writes the copy through a DataHandler of its own, and a hook that
     * fails there fails before core records the copy's uid. The tool can then
     * not tell whether a copy exists; "not copied" would be false where it
     * does, so the call fails (ADR-206).
     */
    #[Test]
    public function aHookThatFailsInTheCopyRunEndsTheCall(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
        $this->failInTheNextWrite(FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to a member function set() on null');

        $this->tool->execute(['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE], $context);
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
    public function copiedTranslationsAreHiddenEvenWhereCoreWouldLeaveThemVisible(): void
    {
        $this->writeSite([0, 1]);
        $this->translateTargetPage();
        // The target page switches core's own hiding off, so only the tool
        // can hide what is copied there.
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEMAIN.table.tt_content.disableHideAtCopy = 1'],
            ['uid' => self::TARGET_PAGE],
        );

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('with 1 hidden translation(s)', $result->content);
        $copyUid = (int)$result->writeTarget?->uid;
        self::assertSame(1, (int)($this->row('tt_content', $copyUid)['hidden'] ?? 0));
        self::assertSame(1, $this->rowCount('tt_content', ['l18n_parent' => $copyUid, 'sys_language_uid' => 1, 'hidden' => 1]));
        self::assertSame(0, $this->rowCount('tt_content', ['l18n_parent' => $copyUid, 'hidden' => 0]));
    }

    #[Test]
    public function aCopyWhoseTranslationTheTargetSiteCannotPlaceIsTakenBack(): void
    {
        $this->writeSite([0]);
        $before = $this->rowCount('tt_content', ['pid' => self::TARGET_PAGE]);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        // Current cores log a refusal for the translation, and the tool takes
        // the copy back. A 13.4 release before the site check drops the
        // translation in silence instead; then the copy stands, hidden, with
        // no translation. Either way no visible row and no stray translation.
        if ($result->isError) {
            self::assertStringContainsString('The copy was deleted again.', $result->content);
            self::assertSame($before, $this->rowCount('tt_content', ['pid' => self::TARGET_PAGE]));
        } else {
            $copyUid = (int)$result->writeTarget?->uid;
            self::assertSame(1, (int)($this->row('tt_content', $copyUid)['hidden'] ?? 0));
            self::assertSame(0, $this->rowCount('tt_content', ['l18n_parent' => $copyUid]));
        }
    }

    #[Test]
    public function aPageTranslationIsNoTarget(): void
    {
        $this->translateTargetPage();

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE_GERMAN],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('page [9] is a translation (language 1) of page [3]', $result->content);
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

        // Core's handling of translations changed in 13.4.25; the card
        // states the rule of the core it runs on.
        $version = new Typo3Version();
        $rule    = $version->getMajorVersion() === 13 && version_compare($version->getVersion(), '13.4.25', '<')
            ? 'this TYPO3 release (before 13.4.25) asks no site, copies a translation onto a target page translated into '
                . 'its language and drops the others without an error'
            : 'a translation is copied only into a site that has its language and onto a target page translated into it '
                . '— where core refuses one, the copy is taken back — and none outside a site';

        self::assertSame([
            'Copy tt_content [20] "Original", language 0',
            'to: page [3] "Target", column 2, directly after element [22] "Anchor"',
            'with its 1 translation(s), as far as core copies them to the target: ' . $rule
            . '; the answer says how many were copied',
            'visibility: the copy and every copied translation are hidden — a human must unhide them before anyone sees them',
        ], $lines);
        self::assertSame($before, $this->rowCount('tt_content', []));
    }

    #[Test]
    public function aWorkspaceDraftOfATranslationIsNotCounted(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 60, 'pid' => self::SOURCE_PAGE, 'colPos' => 0, 'CType' => 'text', 'header' => 'Entwurf',
            'sys_language_uid' => 2, 'l18n_parent' => self::ELEMENT,
            't3ver_wsid' => 1, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertStringStartsWith('with its 1 translation(s),', $lines[2]);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::CLOSED_PAGE];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor('tt_content:hidden,tt_content:colPos')));
    }

    /**
     * A site on the root page with the given languages.
     *
     * @param list<int> $languages
     */
    private function writeSite(array $languages): void
    {
        $siteLanguages = [];
        foreach ($languages as $language) {
            $siteLanguages[] = [
                'languageId' => $language,
                'title'      => 'Language ' . $language,
                'base'       => $language === 0 ? '/' : '/l' . $language . '/',
                'locale'     => $language === 0 ? 'en_US.UTF-8' : 'de_DE.UTF-8',
            ];
        }

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('copytest', [
            'rootPageId' => self::SITE_ROOT,
            'base'       => 'https://example.com/',
            'languages'  => $siteLanguages,
        ]);
    }

    private function translateTargetPage(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::TARGET_PAGE_GERMAN, 'pid' => self::SITE_ROOT, 'title' => 'Ziel', 'doktype' => 1,
            'slug' => '/ziel', 'sys_language_uid' => 1, 'l10n_parent' => self::TARGET_PAGE,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
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
