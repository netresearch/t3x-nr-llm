<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
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
    /** A page title the demo held twice: the page and its translation (NEXT-167). */
    private const TRANSLATED_TITLE = 'Unternehmenspartnerschaften und Projekte';

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

    /**
     * The tool now authorises from the explicit run context (ADR-083) instead of
     * the ambient `$GLOBALS['BE_USER']`; build one that carries the same acting
     * user, so it scopes exactly as it did via the superglobal.
     */
    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }

    #[Test]
    public function equalityFilterSelectsMatchingRowsOnly(): void
    {
        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute([
            'table'        => 'tt_content',
            'where_equals' => ['CType' => 'textmedia'],
            'fields'       => ['header', 'CType'],
        ], $this->contextFor($user))->content;

        self::assertStringContainsString('tt_content:31', $output);
        self::assertStringContainsString('header: Beta', $output);
        self::assertStringNotContainsString('tt_content:30', $output);
    }

    #[Test]
    public function uidFilterReadsASingleRecordWithDefaultLabelFields(): void
    {
        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'uid' => 30], $this->contextFor($user))->content;

        self::assertStringContainsString('tt_content:30', $output);
        self::assertStringContainsString('header: Alpha', $output);
    }

    #[Test]
    public function limitCapsTheRowCount(): void
    {
        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content', 'limit' => 1], $this->contextFor($user))->content;

        self::assertStringContainsString('tt_content:30', $output);
        self::assertStringNotContainsString('tt_content:31', $output);
    }

    #[Test]
    public function emitsATableArtifactFromTheSameFieldsAndCellsAsTheText(): void
    {
        // ADR-108: the run-only TABLE artifact is projected from the SAME
        // TCA-validated field set the text egress uses (uid + pid + requested),
        // so it can never re-expose a column the text path withheld.
        $user = $this->setUpBackendUser(1);

        $result = $this->tool->execute([
            'table'  => 'tt_content',
            'fields' => ['header'],
        ], $this->contextFor($user));

        self::assertCount(1, $result->artifacts);
        $artifact = $result->artifacts[0];
        self::assertSame(ArtifactType::TABLE, $artifact->type);

        // Columns are exactly uid, pid, the requested field and the table's
        // language pair (NEXT-167) — nothing else.
        self::assertSame(['uid', 'pid', 'header', 'sys_language_uid', 'l18n_parent'], $artifact->data['columns']);

        // Rows carry the same formatted cells the text lines show.
        $rows = $artifact->data['rows'];
        self::assertIsArray($rows);

        $headers = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            foreach ($row as $cell) {
                self::assertIsString($cell);
                // Parity: no cell re-exposes a value absent from the text egress.
                self::assertStringContainsString($cell, $result->content);
            }

            $headers[] = $row[2] ?? null;
        }

        self::assertContains('Alpha', $headers);
        self::assertContains('Beta', $headers);
    }

    /**
     * NEXT-167, demo conversation 92: asked to edit the page with a given title
     * under page 10011, the model found "two pages with exactly this title",
     * 10041 and 10042, and asked which one — fourteen times in that
     * conversation. The second was the translation of the first. Every record
     * now says which language it is in and what it translates.
     */
    #[Test]
    public function aPageAndItsTranslationReadAsOneRecordAndItsTranslation(): void
    {
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 10041, 'pid' => 1, 'title' => self::TRANSLATED_TITLE, 'doktype' => 1,
            'sorting' => 3, 'perms_userid' => 1, 'perms_user' => 31,
        ]);
        $pages->insert('pages', [
            'uid' => 10042, 'pid' => 1, 'title' => self::TRANSLATED_TITLE, 'doktype' => 1,
            'sorting' => 3, 'sys_language_uid' => 1, 'l10n_parent' => 10041,
            'perms_userid' => 1, 'perms_user' => 31,
        ]);

        $output = $this->tool->execute([
            'table'        => 'pages',
            'where_equals' => ['title' => self::TRANSLATED_TITLE],
        ], $this->contextFor($this->setUpBackendUser(1)))->content;

        self::assertStringContainsString(
            "- pages:10041\n  pid: 1\n  title: " . self::TRANSLATED_TITLE . "\n  sys_language_uid: 0\n  l10n_parent: 0",
            $output,
        );
        self::assertStringContainsString(
            "- pages:10042\n  pid: 1\n  title: " . self::TRANSLATED_TITLE . "\n  sys_language_uid: 1\n  l10n_parent: 10041",
            $output,
        );
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

        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tt_content'], $this->contextFor($user))->content;

        self::assertStringNotContainsString('DraftSecret', $output);
        self::assertStringNotContainsString('tt_content:99', $output);
        self::assertStringContainsString('tt_content:30', $output);
    }

    #[Test]
    public function nonAdminWithoutTableRightsIsDenied(): void
    {
        $user = $this->setUpBackendUser(2); // editor without any group rights

        $output = $this->tool->execute(['table' => 'tt_content'], $this->contextFor($user))->content;

        self::assertSame('Table not found or not permitted.', $output);
    }

    #[Test]
    public function nonAdminFilteringByForbiddenLanguageIsDenied(): void
    {
        $user = $this->setUpBackendUser(2); // editor
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
        ], $this->contextFor($user))->content;

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

        $user = $this->setUpBackendUser(2);
        $beUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $beUser);
        $beUser->groupData['tables_select']     = 'tt_content';
        $beUser->groupData['webmounts']         = '7';
        $beUser->groupData['allowed_languages'] = '0';

        // No language filter given: the language-0 row is returned, the
        // language-1 row (on the same, accessible page) is dropped and its
        // content never egresses.
        $output = $this->tool->execute(['table' => 'tt_content', 'where_equals' => ['pid' => 7]], $this->contextFor($user))->content;

        self::assertStringContainsString('tt_content:40', $output);
        self::assertStringNotContainsString('tt_content:41', $output);
        self::assertStringNotContainsString('LangOneRow', $output);
    }
}
