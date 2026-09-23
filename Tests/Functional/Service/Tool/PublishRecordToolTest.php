<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\PublishRecordTool;
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
 * The write path of `publish_record` against a real database and the real
 * DataHandler (ADR-198): the hidden flag clears, and only where the acting
 * user holds the page permission, the record-level rights and the field-level
 * grant.
 */
#[CoversClass(PublishRecordTool::class)]
final class PublishRecordToolTest extends AbstractFunctionalTestCase
{
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const PAGE_CLOSED = 1;

    private const PAGE_OPEN = 2;

    private const HIDDEN_PAGE = 3;

    private const ELEMENT_ON_CLOSED = 20;

    private const ELEMENT_ON_OPEN = 21;

    private const VISIBLE_ELEMENT = 22;

    private const TRANSLATED_ELEMENT = 23;

    private const TIMED_ELEMENT = 24;

    private PublishRecordTool $tool;

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
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            // Every bit but the one the tool asks for an element.
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL & ~Permission::CONTENT_EDIT,
        ]);
        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid' => self::HIDDEN_PAGE, 'pid' => self::PAGE_OPEN, 'title' => 'Draft page', 'doktype' => 1,
            'slug' => '/open/draft', 'hidden' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT_ON_CLOSED, self::PAGE_CLOSED, 'Closed draft', 1, 0, 0, 0],
            [self::ELEMENT_ON_OPEN, self::PAGE_OPEN, 'Open draft', 1, 0, 0, 0],
            [self::VISIBLE_ELEMENT, self::PAGE_OPEN, 'Already live', 0, 0, 0, 0],
            [self::TRANSLATED_ELEMENT, self::PAGE_OPEN, 'Übersetzung', 1, 1, self::VISIBLE_ELEMENT, 0],
            [self::TIMED_ELEMENT, self::PAGE_OPEN, 'Timed draft', 1, 0, 0, 1893456000],
        ] as [$uid, $pid, $header, $hidden, $language, $parent, $starttime]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => 'text', 'header' => $header,
                'hidden' => $hidden, 'sys_language_uid' => $language, 'l18n_parent' => $parent,
                'starttime' => $starttime,
            ]);
        }

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2']);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new PublishRecordTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminPublishesAHiddenContentElement(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_OPEN],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Cleared the hidden flag of tt_content [21]', $result->content);
        self::assertSame(0, $this->hiddenOf('tt_content', self::ELEMENT_ON_OPEN));
        self::assertSame(WriteKind::UPDATED, $result->writeKind);
        self::assertSame(self::ELEMENT_ON_OPEN, $result->writeTarget?->uid);
    }

    #[Test]
    public function anAdminPublishesAHiddenPage(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::HIDDEN_PAGE],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, $this->hiddenOf('pages', self::HIDDEN_PAGE));
    }

    #[Test]
    public function anEditorWithTheGrantsPublishesOnAPageTheyMayEdit(): void
    {
        $editor = $this->editor('tt_content:hidden');

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_OPEN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, $this->hiddenOf('tt_content', self::ELEMENT_ON_OPEN));
    }

    #[Test]
    public function anEditorWithTheGrantsPublishesAPageTheyMayEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::HIDDEN_PAGE],
            ToolExecutionContext::fromBackendUser($this->editor('pages:hidden')),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, $this->hiddenOf('pages', self::HIDDEN_PAGE));
    }

    #[Test]
    public function anEditorWithoutTheFieldGrantIsRefusedBeforeTheWrite(): void
    {
        $editor = $this->editor('');

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_OPEN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        // Refused by the tool's own question, not by the read-back after a
        // write the DataHandler dropped in silence.
        self::assertStringContainsString('grant for tt_content:hidden', $result->content);
        self::assertStringContainsString('Nothing was written.', $result->content);
        self::assertSame(1, $this->hiddenOf('tt_content', self::ELEMENT_ON_OPEN));
    }

    #[Test]
    public function anEditorMayNotPublishOnAPageTheyMayNotEdit(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_CLOSED],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:hidden')),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(1, $this->hiddenOf('tt_content', self::ELEMENT_ON_CLOSED));
    }

    #[Test]
    public function anEditorMayNotPublishInALanguageTheyMayNotEdit(): void
    {
        $editor                                 = $this->editor('tt_content:hidden');
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::TRANSLATED_ELEMENT],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(1, $this->hiddenOf('tt_content', self::TRANSLATED_ELEMENT));
    }

    #[Test]
    public function aComplaintOfTheDataHandlerDoesNotHideThatTheFlagCleared(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complain = true;

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_OPEN],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        // The record IS published: the answer says so, names it as written,
        // and passes the complaint on.
        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('TYPO3 reported: ', $result->content);
        self::assertSame(self::ELEMENT_ON_OPEN, $result->writeTarget?->uid);
        self::assertSame(0, $this->hiddenOf('tt_content', self::ELEMENT_ON_OPEN));
    }

    #[Test]
    public function anEditorMayNotPublishALockedRecord(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['editlock' => 1], ['uid' => self::ELEMENT_ON_OPEN]);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_OPEN],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:hidden')),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertSame(1, $this->hiddenOf('tt_content', self::ELEMENT_ON_OPEN));
    }

    #[Test]
    public function aMissingRecordIsRefusedInTheSameWordsAsAForbiddenOne(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => 987654],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
    }

    #[Test]
    public function aVisibleRecordIsNotWrittenAndNamesNoTarget(): void
    {
        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::VISIBLE_ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('is not hidden; nothing was written', $result->content);
        self::assertNull($result->writeTarget);
    }

    #[Test]
    public function thePreviewNamesWhatStillRestrictsTheRecordAndWritesNothing(): void
    {
        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::TIMED_ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertStringContainsString('tt_content [24] "Timed draft" on page [2] "Open", language 0:', $lines[0]);
        self::assertSame('hidden: 1 → 0 (hidden → published)', $lines[1]);
        self::assertSame('may still restrict it: start time 2030-01-01T00:00:00Z', $lines[2]);
        self::assertSame(1, $this->hiddenOf('tt_content', self::TIMED_ELEMENT));
    }

    #[Test]
    public function thePreviewOfATranslationNamesAHiddenDefaultLanguageRecord(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['hidden' => 1], ['uid' => self::VISIBLE_ELEMENT]);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::TRANSLATED_ELEMENT],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertContains('may still restrict it: its default-language record [22] is hidden', $lines);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_CLOSED];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor('tt_content:hidden')));
    }

    private function editor(string $nonExcludeFields): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages,tt_content';
        $editor->groupData['non_exclude_fields'] = $nonExcludeFields;
        // `tt_content.CType` declares authMode, so an editor needs the grant
        // for the type of every element they touch.
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        return $editor;
    }

    private function hiddenOf(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $hidden = $queryBuilder
            ->select('hidden')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return (int)$hidden;
    }
}
