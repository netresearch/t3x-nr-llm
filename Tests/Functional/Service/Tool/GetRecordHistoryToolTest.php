<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetRecordHistoryTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Functional tests for GetRecordHistoryTool (ADR-046): history renders
 * newest-first with resolved usernames and old → new values; credential-like
 * field values are withheld; sensitive tables stay denied.
 */
#[CoversClass(GetRecordHistoryTool::class)]
final class GetRecordHistoryToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable('sys_history');
        self::assertInstanceOf(Connection::class, $connection);

        // Two modifications by admin (uid 1), oldest first; a third row on a
        // sensitive column proves value withholding.
        $connection->insert('sys_history', [
            'tstamp'       => 1700000000,
            'actiontype'   => 2,
            'usertype'     => 'BE',
            'userid'       => 1,
            'recuid'       => 5,
            'tablename'    => 'tt_content',
            'history_data' => '{"oldRecord":{"header":"Old headline"},"newRecord":{"header":"New headline"}}',
            'workspace'    => 0,
        ]);
        $connection->insert('sys_history', [
            'tstamp'       => 1700000600,
            'actiontype'   => 2,
            'usertype'     => 'BE',
            'userid'       => 2,
            'recuid'       => 5,
            'tablename'    => 'tt_content',
            'history_data' => '{"oldRecord":{"bodytext":"Lorem"},"newRecord":{"bodytext":"Ipsum"}}',
            'workspace'    => 0,
        ]);
        $connection->insert('sys_history', [
            'tstamp'       => 1700001200,
            'actiontype'   => 2,
            'usertype'     => 'BE',
            'userid'       => 1,
            'recuid'       => 5,
            'tablename'    => 'tt_content',
            'history_data' => '{"oldRecord":{"secret_token":"oldtoken123"},"newRecord":{"secret_token":"newtoken456"}}',
            'workspace'    => 0,
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('get_record_history');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function rendersNewestFirstWithUsernamesAndValues(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 5])->content;

        self::assertStringContainsString('Change history for tt_content:5 (3 entries, newest first):', $output);
        self::assertStringContainsString("header: 'Old headline' → 'New headline'", $output);
        self::assertStringContainsString('by admin (modify)', $output);
        self::assertStringContainsString('by editor (modify)', $output);
        // Newest (secret_token change) before oldest (header change).
        $posSecret = strpos($output, 'secret_token:');
        $posHeader = strpos($output, "header: 'Old headline'");
        self::assertIsInt($posSecret);
        self::assertIsInt($posHeader);
        self::assertLessThan($posHeader, $posSecret);
    }

    #[Test]
    public function withholdsSensitiveFieldValues(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 5])->content;

        self::assertStringContainsString('secret_token: [changed — detail withheld]', $output);
        self::assertStringNotContainsString('oldtoken123', $output);
        self::assertStringNotContainsString('newtoken456', $output);
    }

    #[Test]
    public function fieldFilterSkipsUnrelatedEntries(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 5, 'field' => 'header'])->content;

        self::assertStringContainsString('filtered to "header"', $output);
        self::assertStringContainsString("header: 'Old headline' → 'New headline'", $output);
        self::assertStringNotContainsString('bodytext', $output);
        self::assertStringContainsString('2 entries not touching "header" skipped', $output);
    }

    #[Test]
    public function reportsWhenNoHistoryExists(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'No change history for tt_content:999.',
            $this->tool->execute(['table' => 'tt_content', 'uid' => 999])->content,
        );
    }

    #[Test]
    public function deniesSensitiveTableEvenForAdmin(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'Table not found or not permitted.',
            $this->tool->execute(['table' => 'be_users', 'uid' => 1])->content,
        );
    }

    #[Test]
    public function deniesWithoutBackendUser(): void
    {
        self::assertSame(
            'Table not found or not permitted.',
            $this->tool->execute(['table' => 'tt_content', 'uid' => 5])->content,
        );
    }

    #[Test]
    public function nonAdminWithoutPageAccessIsDenied(): void
    {
        // Editor MAY select tt_content but has no PAGE_SHOW on page 9 — the
        // per-record page gate must deny (values must not leak from
        // unreadable pages).
        $this->grantEditorTableSelect();
        $this->insertPageWithContentAndHistory(9, 0, 40);
        $this->setUpBackendUser(2);

        self::assertSame(
            'Table not found or not permitted.',
            $this->tool->execute(['table' => 'tt_content', 'uid' => 40])->content,
        );
    }

    #[Test]
    public function nonAdminWithPageAccessSeesHistory(): void
    {
        // Same editor, but page 9 grants PAGE_SHOW to everybody.
        $this->grantEditorTableSelect();
        $this->insertPageWithContentAndHistory(9, Permission::PAGE_SHOW, 40);
        $this->setUpBackendUser(2);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 40])->content;

        self::assertStringContainsString('Change history for tt_content:40', $output);
        self::assertStringContainsString("header: 'A' → 'B'", $output);
    }

    private function grantEditorTableSelect(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $groups = $connectionPool->getConnectionForTable('be_groups');
        self::assertInstanceOf(Connection::class, $groups);
        // db_mountpoints + options=3: readPageAccess also requires the page
        // inside a webmount, and group mounts are only inherited with the
        // options bits set.
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Readers',
            'tables_select' => 'tt_content,pages', 'db_mountpoints' => '9',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);
    }

    private function insertPageWithContentAndHistory(int $pageUid, int $permsEverybody, int $contentUid): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->insert('pages', [
            'uid' => $pageUid, 'pid' => 0, 'title' => 'Gated', 'doktype' => 1, 'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => $permsEverybody,
        ]);
        $connection->insert('tt_content', [
            'uid' => $contentUid, 'pid' => $pageUid, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'B',
        ]);
        $connection->insert('sys_history', [
            'tstamp'       => 1700000000,
            'actiontype'   => 2,
            'usertype'     => 'BE',
            'userid'       => 1,
            'recuid'       => $contentUid,
            'tablename'    => 'tt_content',
            'history_data' => '{"oldRecord":{"header":"A"},"newRecord":{"header":"B"}}',
            'workspace'    => 0,
        ]);
    }
}
