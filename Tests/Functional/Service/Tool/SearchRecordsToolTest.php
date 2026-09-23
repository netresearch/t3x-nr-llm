<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Functional tests for SearchRecordsTool (ADR-042).
 *
 * Load-bearing: the search finds seeded content through the TCA
 * searchFields, hidden rows never reach the output, and the sensitive-table
 * denylist holds even for an admin.
 */
#[CoversClass(SearchRecordsTool::class)]
final class SearchRecordsToolTest extends AbstractFunctionalTestCase
{
    private SearchRecordsTool $tool;

    private ToolExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $user = $this->setUpBackendUser(1); // admin
        $this->context = ToolExecutionContext::fromBackendUser($user);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $collector = $this->get(SearchableSchemaFieldsCollector::class);
        self::assertInstanceOf(SearchableSchemaFieldsCollector::class, $collector);
        $this->tool = new SearchRecordsTool($connectionPool, new TableReadAccessService(), $collector);

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'sorting' => 1,
        ]);

        $content = $connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 10, 'pid' => 1, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'About Netresearch', 'bodytext' => 'Netresearch builds durable web platforms.',
        ]);
        $content->insert('tt_content', [
            'uid' => 11, 'pid' => 1, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Hidden teaser', 'bodytext' => 'Netresearch hidden gem.', 'hidden' => 1,
        ]);
    }

    #[Test]
    public function findsSeededContentThroughSearchFieldsWithExcerpt(): void
    {
        $output = $this->tool->execute(['query' => 'Netresearch'], $this->context)->content;

        self::assertStringContainsString('tt_content:10', $output);
        self::assertStringContainsString('About Netresearch', $output);
        self::assertStringContainsString('match(', $output);
    }

    /**
     * NEXT-167, demo conversation 92: a page and its translation share the
     * title, so a title search found "two pages with exactly this title" and
     * the model asked which one to edit. The hits now say which is which.
     */
    #[Test]
    public function aHitNamesItsLanguageAndTheRecordItTranslates(): void
    {
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 10057, 'pid' => 1, 'title' => 'Prof. Dr. Erika Mustermann', 'doktype' => 1, 'sorting' => 5,
        ]);
        $pages->insert('pages', [
            'uid' => 10058, 'pid' => 1, 'title' => 'Prof. Dr. Erika Mustermann', 'doktype' => 1, 'sorting' => 5,
            'sys_language_uid' => 1, 'l10n_parent' => 10057,
        ]);

        $output = $this->tool->execute(['query' => 'Erika Mustermann', 'table' => 'pages'], $this->context)->content;

        self::assertStringContainsString('pages:10057 · Prof. Dr. Erika Mustermann · pid 1 · language 0', $output);
        self::assertStringContainsString('pages:10058 · Prof. Dr. Erika Mustermann · pid 1 · language 1 · translation of pages:10057', $output);
        self::assertStringNotContainsString('language 0 · translation of', $output);
    }

    /**
     * The language filter read_records already applies: a non-admin restricted
     * to the default language gets no hit in another language, although the
     * page is readable to them.
     */
    #[Test]
    public function aNonAdminGetsNoHitInALanguageTheyMayNotAccess(): void
    {
        $pool  = $this->get(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 7, 'pid' => 0, 'title' => 'Public', 'doktype' => 1,
            'sorting' => 7, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 40, 'pid' => 7, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Languagemarker default', 'sys_language_uid' => 0,
        ]);
        $content->insert('tt_content', [
            'uid' => 41, 'pid' => 7, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Languagemarker translated', 'sys_language_uid' => 1, 'l18n_parent' => 40,
        ]);

        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_select']     = 'tt_content';
        $editor->groupData['webmounts']         = '7';
        $editor->groupData['allowed_languages'] = '0';

        $output = $this->tool->execute(
            ['query' => 'Languagemarker', 'table' => 'tt_content'],
            ToolExecutionContext::fromBackendUser($editor),
        )->content;

        self::assertStringContainsString('tt_content:40', $output);
        self::assertStringNotContainsString('tt_content:41', $output);
        self::assertStringNotContainsString('translated', $output);
    }

    /**
     * Rows in a forbidden language must not use up the limit: with limit 1 and
     * the forbidden row first by uid, the permitted one is still found.
     */
    #[Test]
    public function rowsInAForbiddenLanguageDoNotUseUpTheLimit(): void
    {
        $pool  = $this->get(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 7, 'pid' => 0, 'title' => 'Public', 'doktype' => 1,
            'sorting' => 7, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 40, 'pid' => 7, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Limitmarker translated', 'sys_language_uid' => 1,
        ]);
        $content->insert('tt_content', [
            'uid' => 41, 'pid' => 7, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Limitmarker default', 'sys_language_uid' => 0,
        ]);

        $editor = $this->setUpBackendUser(2);
        $editor->groupData['tables_select']     = 'tt_content';
        $editor->groupData['webmounts']         = '7';
        $editor->groupData['allowed_languages'] = '0';

        $output = $this->tool->execute(
            ['query' => 'Limitmarker', 'table' => 'tt_content', 'limit' => 1],
            ToolExecutionContext::fromBackendUser($editor),
        )->content;

        self::assertStringContainsString('tt_content:41', $output);
        self::assertStringNotContainsString('tt_content:40', $output);
    }

    #[Test]
    public function hiddenRowsNeverReachTheOutput(): void
    {
        $output = $this->tool->execute(['query' => 'hidden gem'], $this->context)->content;

        self::assertSame('No matches.', $output);
    }

    #[Test]
    public function tableRestrictionLimitsTheSearch(): void
    {
        $output = $this->tool->execute(['query' => 'Netresearch', 'table' => 'pages'], $this->context)->content;

        self::assertStringNotContainsString('tt_content:', $output);
    }

    #[Test]
    public function sensitiveTableIsDeniedEvenForAdmin(): void
    {
        $output = $this->tool->execute(['query' => 'admin', 'table' => 'be_users'], $this->context)->content;

        self::assertSame('Table not found or not permitted.', $output);
    }

    #[Test]
    public function workspaceDraftRowsNeverReachTheOutput(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        // Unpublished workspace draft (t3ver_wsid > 0) must never egress to the LLM.
        $content->insert('tt_content', [
            'uid' => 14, 'pid' => 1, 'colPos' => 0, 'sorting' => 3, 'CType' => 'text',
            'header' => 'Draft', 'bodytext' => 'Netresearch workspacedraftmarker.',
            't3ver_wsid' => 1, 't3ver_oid' => 10, 't3ver_state' => 0,
        ]);

        $output = $this->tool->execute(['query' => 'workspacedraftmarker'], $this->context)->content;

        self::assertSame('No matches.', $output);
    }
}
