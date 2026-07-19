<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Functional tests for GetPageContentTool (ADR-042).
 *
 * Load-bearing: content elements come back in column/sorting order, hidden
 * elements are admin-only (marked), and a non-admin without page-show
 * permission is denied with the same neutral string as a missing page
 * (fail-closed).
 */
#[CoversClass(GetPageContentTool::class)]
final class GetPageContentToolTest extends AbstractFunctionalTestCase
{
    private GetPageContentTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new GetPageContentTool($connectionPool);

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        // Owned by admin (uid 1); no group/everybody perms — the editor
        // (uid 2, non-admin) must not pass the PAGE_SHOW check.
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1, 'perms_userid' => 1, 'perms_user' => 31,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);

        $content = $connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 20, 'pid' => 1, 'colPos' => 1, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Sidebar', 'bodytext' => '<p>Side <b>note</b>.</p>',
        ]);
        $content->insert('tt_content', [
            'uid' => 21, 'pid' => 1, 'colPos' => 0, 'sorting' => 1, 'CType' => 'textmedia',
            'header' => 'Intro', 'bodytext' => '<p>Welcome to the intro paragraph.</p>',
        ]);
        $content->insert('tt_content', [
            'uid' => 22, 'pid' => 1, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Secret draft', 'bodytext' => 'Draft body', 'hidden' => 1,
        ]);
    }

    #[Test]
    public function adminSeesElementsInColumnAndSortingOrderIncludingMarkedHidden(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['uid' => 1]);

        self::assertStringContainsString('Page [1] Home (doktype 1, slug /)', $output);
        // colPos 0 before colPos 1; hidden element present and marked.
        $intro   = mb_strpos($output, '[21] colPos=0 textmedia · Intro');
        $draft   = mb_strpos($output, '[22] colPos=0 text · Secret draft [hidden]');
        $sidebar = mb_strpos($output, '[20] colPos=1 text · Sidebar');
        self::assertNotFalse($intro);
        self::assertNotFalse($draft);
        self::assertNotFalse($sidebar);
        self::assertLessThan($draft, $intro);
        self::assertLessThan($sidebar, $draft);
        // Tag-stripped excerpt.
        self::assertStringContainsString('Welcome to the intro paragraph.', $output);
        self::assertStringNotContainsString('<p>', $output);
    }

    #[Test]
    public function workspaceDraftContentIsExcludedEvenForAdmin(): void
    {
        $content = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        // Unpublished workspace draft (t3ver_wsid > 0) must never egress to the
        // LLM — not even for an admin, who may otherwise inspect hidden rows.
        $content->insert('tt_content', [
            'uid' => 29, 'pid' => 1, 'colPos' => 0, 'sorting' => 9, 'CType' => 'text',
            'header' => 'DraftOnlyMarker', 'bodytext' => 'draft body',
            't3ver_wsid' => 1, 't3ver_oid' => 21, 't3ver_state' => 0,
        ]);

        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['uid' => 1]);

        self::assertStringNotContainsString('DraftOnlyMarker', $output);
        self::assertStringContainsString('Intro', $output);
    }

    #[Test]
    public function nonAdminWithoutPagePermissionIsDenied(): void
    {
        $this->setUpBackendUser(2); // editor, no rights on page 1

        $output = $this->tool->execute(['uid' => 1]);

        self::assertSame('Page not found or not permitted.', $output);
    }

    #[Test]
    public function missingPageReturnsTheSameNeutralString(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['uid' => 999]);

        self::assertSame('Page not found or not permitted.', $output);
    }

    #[Test]
    public function nonAdminWithoutLanguageAccessIsDenied(): void
    {
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        // A root-level page the editor can fully reach (own rootline, everybody
        // perms, in web mount below) so the language gate is the only variable.
        $pages->insert('pages', [
            'uid' => 5, 'pid' => 0, 'title' => 'Public', 'doktype' => 1, 'slug' => '/public',
            'sorting' => 5, 'perms_everybody' => Permission::ALL,
        ]);

        $this->setUpBackendUser(2); // editor (non-admin)
        $beUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $beUser);
        // Web mount covers page 5 (readPageAccess requires isInWebMount); the
        // acting user is restricted to the default language only.
        $beUser->groupData['webmounts']         = '5';
        $beUser->groupData['allowed_languages'] = '0';

        // Language 0 is permitted -> the page resolves past the language gate.
        self::assertStringContainsString(
            'Page [5] Public',
            $this->tool->execute(['uid' => 5, 'language' => 0]),
        );
        // Language 1 is not permitted -> neutral denial at the language gate.
        self::assertSame(
            'Page not found or not permitted.',
            $this->tool->execute(['uid' => 5, 'language' => 1]),
        );
    }
}
