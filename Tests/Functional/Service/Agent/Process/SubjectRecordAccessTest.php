<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Agent\Process\SubjectRecordAccess;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Whether the acting user may read the record a process is about (ADR-228):
 * the table, the language, and — for an editor — the page's web mount and
 * page-show permission, checked on the acting user rather than the global
 * backend user.
 */
#[CoversClass(SubjectRecordAccess::class)]
final class SubjectRecordAccessTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $pool = $this->pool();
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'               => 1,
            'pid'               => 0,
            'title'             => 'Editors',
            'tables_select'     => 'pages,tt_content',
            'db_mountpoints'    => '1,2',
            'allowed_languages' => '0',
        ]);
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1', 'options' => 3], ['uid' => 2]);

        foreach ([
            // uid, show for everybody, deleted
            [1, 1, 0],
            [2, 0, 0],
            [3, 1, 1],
            [4, 1, 0],
        ] as [$uid, $show, $deleted]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid'             => $uid,
                'pid'             => 0,
                'title'           => 'Page ' . $uid,
                'perms_everybody' => $show,
                'perms_user'      => 0,
                'perms_group'     => 0,
                'perms_userid'    => 0,
                'perms_groupid'   => 0,
                'deleted'         => $deleted,
            ]);
        }

        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'header' => 'On page 1']);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 20, 'pid' => 0, 'header' => 'On the root level']);
    }

    #[Test]
    public function anEditorReadsAPageInTheirMountWithShowPermission(): void
    {
        self::assertTrue($this->readable($this->editor(), 'pages', 1));
        self::assertTrue($this->readable($this->editor(), 'tt_content', 10), 'an element on that page');
    }

    #[Test]
    public function anEditorDoesNotReadAPageWithoutShowPermissionOrOutsideTheirMount(): void
    {
        self::assertFalse($this->readable($this->editor(), 'pages', 2), 'no show permission');
        self::assertFalse($this->readable($this->editor(), 'pages', 4), 'outside the mount');
        self::assertTrue($this->readable($this->admin(), 'pages', 2));
        self::assertTrue($this->readable($this->admin(), 'pages', 4));
    }

    #[Test]
    public function aRecordOnTheRootLevelIsForAdministratorsOnly(): void
    {
        self::assertFalse($this->readable($this->editor(), 'tt_content', 20));
        self::assertTrue($this->readable($this->admin(), 'tt_content', 20));
    }

    #[Test]
    public function aDeletedOrMissingRecordIsReadByNobody(): void
    {
        self::assertFalse($this->readable($this->admin(), 'pages', 3));
        self::assertFalse($this->readable($this->admin(), 'pages', 99));
        self::assertFalse($this->readable($this->editor(), 'pages', 3));
    }

    #[Test]
    public function aLanguageTheEditorMayNotEditIsNotRead(): void
    {
        self::assertFalse($this->readable($this->editor(), 'pages', 1, 1));
        self::assertTrue($this->readable($this->admin(), 'pages', 1, 1));
    }

    #[Test]
    public function aTableTheUserMayNotReadIsNotRead(): void
    {
        self::assertFalse($this->readable($this->editor(), 'sys_category', 1));
        self::assertFalse($this->readable($this->admin(), 'be_users', 1), 'a sensitive table, even for an administrator');
    }

    #[Test]
    public function withoutAnActingUserNothingIsRead(): void
    {
        self::assertFalse((new SubjectRecordAccess($this->pool(), new TableReadAccessService()))->isReadable(null, new SubjectRecord(new RecordReference('pages', 1))));
    }

    private function readable(BackendUserAuthentication $user, string $table, int $uid, int $language = 0): bool
    {
        return (new SubjectRecordAccess($this->pool(), new TableReadAccessService()))
            ->isReadable($user, new SubjectRecord(new RecordReference($table, $uid), $language));
    }

    private function editor(): BackendUserAuthentication
    {
        return $this->setUpBackendUser(2);
    }

    private function admin(): BackendUserAuthentication
    {
        return $this->setUpBackendUser(1);
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
