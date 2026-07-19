<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Functional tests for ReadRecordsTool (ADR-042).
 *
 * Load-bearing: equality filters bind as named parameters and select the
 * right rows, requested fields are TCA-validated, and a non-admin without
 * table rights (or page-show permission) gets nothing.
 */
#[CoversClass(ReadRecordsTool::class)]
final class ReadRecordsToolTest extends AbstractFunctionalTestCase
{
    private ReadRecordsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new ReadRecordsTool($connectionPool, new TableReadAccessService());

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 0,
        ]);

        $content = $connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 30, 'pid' => 1, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Alpha',
        ]);
        $content->insert('tt_content', [
            'uid' => 31, 'pid' => 1, 'colPos' => 0, 'sorting' => 2,
            'CType' => 'textmedia', 'header' => 'Beta',
        ]);
    }

    #[Test]
    public function equalityFilterSelectsMatchingRowsOnly(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute([
            'table'        => 'tt_content',
            'where_equals' => ['CType' => 'textmedia'],
            'fields'       => ['header', 'CType'],
        ]);

        self::assertStringContainsString('tt_content:31', $output);
        self::assertStringContainsString('header: Beta', $output);
        self::assertStringNotContainsString('tt_content:30', $output);
    }

    #[Test]
    public function uidFilterReadsASingleRecordWithDefaultLabelFields(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 30]);

        self::assertStringContainsString('tt_content:30', $output);
        self::assertStringContainsString('header: Alpha', $output);
    }

    #[Test]
    public function limitCapsTheRowCount(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'limit' => 1]);

        self::assertStringContainsString('tt_content:30', $output);
        self::assertStringNotContainsString('tt_content:31', $output);
    }

    #[Test]
    public function workspaceDraftRowsAreExcluded(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        // Unpublished workspace draft (t3ver_wsid > 0) must never egress to the LLM.
        $content->insert('tt_content', [
            'uid' => 99, 'pid' => 1, 'colPos' => 0, 'sorting' => 9,
            'CType' => 'text', 'header' => 'DraftSecret',
            't3ver_wsid' => 1, 't3ver_oid' => 30, 't3ver_state' => 0,
        ]);

        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content']);

        self::assertStringNotContainsString('DraftSecret', $output);
        self::assertStringNotContainsString('tt_content:99', $output);
        self::assertStringContainsString('tt_content:30', $output);
    }

    #[Test]
    public function nonAdminWithoutTableRightsIsDenied(): void
    {
        $this->setUpBackendUser(2); // editor without any group rights

        $output = $this->tool->execute(['table' => 'tt_content']);

        self::assertSame('Table not found or not permitted.', $output);
    }

    #[Test]
    public function nonAdminFilteringByForbiddenLanguageIsDenied(): void
    {
        $this->setUpBackendUser(2); // editor
        $beUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $beUser);
        // Grant table read but restrict to the default language only.
        $beUser->groupData['tables_select']     = 'tt_content';
        $beUser->groupData['allowed_languages'] = '0';

        // Explicitly filtering by the forbidden language 1 -> neutral denial,
        // before any row is fetched.
        $output = $this->tool->execute([
            'table'        => 'tt_content',
            'where_equals' => ['sys_language_uid' => 1],
        ]);

        self::assertSame('Table not found or not permitted.', $output);
    }

    #[Test]
    public function nonAdminNeverSeesForbiddenLanguageRowsEvenWithoutAFilter(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        // A page the editor can reach (root page, everybody-show, in web mount).
        $pages->insert('pages', [
            'uid' => 7, 'pid' => 0, 'title' => 'Public', 'doktype' => 1,
            'sorting' => 7, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $content = $connectionPool->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        $content->insert('tt_content', [
            'uid' => 40, 'pid' => 7, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'LangZeroRow', 'sys_language_uid' => 0,
        ]);
        $content->insert('tt_content', [
            'uid' => 41, 'pid' => 7, 'colPos' => 0, 'sorting' => 2,
            'CType' => 'text', 'header' => 'LangOneRow', 'sys_language_uid' => 1,
        ]);

        $this->setUpBackendUser(2);
        $beUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $beUser);
        $beUser->groupData['tables_select']     = 'tt_content';
        $beUser->groupData['webmounts']         = '7';
        $beUser->groupData['allowed_languages'] = '0';

        // No language filter given: the language-0 row is returned, the
        // language-1 row (on the same, accessible page) is dropped and its
        // content never egresses.
        $output = $this->tool->execute(['table' => 'tt_content', 'where_equals' => ['pid' => 7]]);

        self::assertStringContainsString('tt_content:40', $output);
        self::assertStringNotContainsString('tt_content:41', $output);
        self::assertStringNotContainsString('LangOneRow', $output);
    }
}
