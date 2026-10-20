<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetRecordHistoryTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolveUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The read tools answer page access from the run's ACTING user's web mounts,
 * whoever is in `$GLOBALS['BE_USER']` (#1022).
 *
 * `BackendUtility::readPageAccess()` takes the acting user's perms clause but
 * asks the ambient user for `isInWebMount()`. Every page here passes the
 * editor's perms clause (everybody may do everything), so the web mount is the
 * only thing that separates page 2 (mounted) from page 3 (not mounted).
 *
 * Each test runs twice: with an admin as the ambient user — on the old code the
 * unmounted page was then readable — and with no ambient user at all, as in a
 * worker or CLI run — on the old code every non-admin check then called
 * `isInWebMount()` on null.
 */
#[CoversClass(GetPageContentTool::class)]
#[CoversClass(GetRecordHistoryTool::class)]
#[CoversClass(ReadRecordsTool::class)]
#[CoversClass(ResolveUrlTool::class)]
#[CoversClass(SearchRecordsTool::class)]
final class ReadToolsActingUserWebMountTest extends AbstractFunctionalTestCase
{
    private const EDITOR = 2;

    private const ADMIN = 1;

    private const MOUNTED_PAGE = 2;

    private const UNMOUNTED_PAGE = 3;

    private const TRANSLATION_OF_MOUNTED_PAGE = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $siteDir = $this->instancePath . '/typo3conf/sites/mounts';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost:59999/'
            languages:
              - languageId: 0
                title: English
                locale: en_US.UTF-8
                base: /
              - languageId: 1
                title: German
                locale: de_DE.UTF-8
                base: /de/
            YAML);

        $pool  = $this->get(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $everything = ['perms_userid' => self::ADMIN, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL];
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'sorting' => 1, 'is_siteroot' => 1, 'slug' => '/'] + $everything);
        $pages->insert('pages', ['uid' => self::MOUNTED_PAGE, 'pid' => 1, 'title' => 'Mounted', 'doktype' => 1, 'sorting' => 2, 'slug' => '/mounted'] + $everything);
        $pages->insert('pages', ['uid' => self::UNMOUNTED_PAGE, 'pid' => 1, 'title' => 'Unmounted', 'doktype' => 1, 'sorting' => 3, 'slug' => '/unmounted'] + $everything);
        $pages->insert('pages', [
            'uid'  => self::TRANSLATION_OF_MOUNTED_PAGE, 'pid' => 1, 'title' => 'Eingehaengt', 'doktype' => 1, 'sorting' => 2,
            'slug' => '/mounted', 'sys_language_uid' => 1, 'l10n_parent' => self::MOUNTED_PAGE,
        ] + $everything);

        $content = $pool->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        $content->insert('tt_content', ['uid' => 20, 'pid' => self::MOUNTED_PAGE, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text', 'header' => 'Mountmarker mounted']);
        $content->insert('tt_content', ['uid' => 21, 'pid' => self::MOUNTED_PAGE, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text', 'header' => 'Mountmarker mounted draft', 'hidden' => 1]);
        $content->insert('tt_content', [
            'uid'              => 22, 'pid' => self::MOUNTED_PAGE, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text', 'header' => 'Mountmarker eingehaengt',
            'sys_language_uid' => 1, 'l18n_parent' => 20,
        ]);
        $content->insert('tt_content', ['uid' => 30, 'pid' => self::UNMOUNTED_PAGE, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text', 'header' => 'Mountmarker unmounted']);
        $content->insert('tt_content', ['uid' => 31, 'pid' => self::UNMOUNTED_PAGE, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text', 'header' => 'Mountmarker unmounted draft', 'hidden' => 1]);

        $history = $pool->getConnectionForTable('sys_history');
        self::assertInstanceOf(Connection::class, $history);
        foreach ([20, 30] as $recordUid) {
            $history->insert('sys_history', [
                'tstamp'       => 1700000000, 'actiontype' => 2, 'usertype' => 'BE', 'userid' => self::ADMIN,
                'recuid'       => $recordUid, 'tablename' => 'tt_content', 'workspace' => 0,
                'history_data' => '{"oldRecord":{"header":"Before"},"newRecord":{"header":"After"}}',
            ]);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambientUsers(): iterable
    {
        yield 'an admin is the ambient user' => ['admin'];
        yield 'no ambient user (worker, CLI)' => ['none'];
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function getPageContentFollowsTheActingUsersMounts(string $ambient): void
    {
        $context = $this->actingEditorWith($ambient);
        $tool    = $this->tool('get_page_content');

        self::assertStringContainsString('Page [2] Mounted', $tool->execute(['uid' => self::MOUNTED_PAGE], $context)->content);
        self::assertSame('Page not found or not permitted.', $tool->execute(['uid' => self::UNMOUNTED_PAGE], $context)->content);
    }

    /**
     * The second check, on the page a translation translates. The web mount of
     * a translation is already its source page's, so only the run without an
     * ambient user tells this check apart from the first one.
     */
    #[Test]
    #[DataProvider('ambientUsers')]
    public function getPageContentReadsATranslationOfAMountedPage(string $ambient): void
    {
        $context = $this->actingEditorWith($ambient);

        $output = $this->tool('get_page_content')->execute(['uid' => self::TRANSLATION_OF_MOUNTED_PAGE, 'language' => 1], $context)->content;

        self::assertStringContainsString('translation of page [2]', $output);
        self::assertStringContainsString('Mountmarker eingehaengt', $output);
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function getRecordHistoryFollowsTheActingUsersMounts(string $ambient): void
    {
        $context = $this->actingEditorWith($ambient);
        $tool    = $this->tool('get_record_history');

        self::assertStringContainsString('Change history for tt_content:20', $tool->execute(['table' => 'tt_content', 'uid' => 20], $context)->content);
        self::assertSame('Table not found or not permitted.', $tool->execute(['table' => 'tt_content', 'uid' => 30], $context)->content);
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function readRecordsFollowsTheActingUsersMounts(string $ambient): void
    {
        $output = $this->tool('read_records')->execute(['table' => 'tt_content'], $this->actingEditorWith($ambient))->content;

        self::assertStringContainsString('tt_content:20', $output);
        self::assertStringNotContainsString('tt_content:30', $output);
    }

    /**
     * A hidden row additionally passes the edit check of ReadsHiddenRecordsTrait.
     * The unmounted draft is already removed by the show check before it, so
     * this pins the trait's own check through the run without an ambient user.
     */
    #[Test]
    #[DataProvider('ambientUsers')]
    public function readRecordsReturnsAHiddenRowOnlyOnAMountedPage(string $ambient): void
    {
        $output = $this->tool('read_records')->execute(['table' => 'tt_content', 'include_hidden' => true], $this->actingEditorWith($ambient))->content;

        self::assertStringContainsString('tt_content:21 (hidden)', $output);
        self::assertStringNotContainsString('tt_content:31', $output);
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function searchRecordsFollowsTheActingUsersMounts(string $ambient): void
    {
        $output = $this->tool('search_records')->execute(['query' => 'Mountmarker', 'table' => 'tt_content'], $this->actingEditorWith($ambient))->content;

        self::assertStringContainsString('tt_content:20', $output);
        self::assertStringNotContainsString('tt_content:30', $output);
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function searchRecordsFindsAHiddenRowOnlyOnAMountedPage(string $ambient): void
    {
        $output = $this->tool('search_records')->execute(
            ['query' => 'Mountmarker', 'table' => 'tt_content', 'include_hidden' => true],
            $this->actingEditorWith($ambient),
        )->content;

        self::assertStringContainsString('tt_content:21', $output);
        self::assertStringNotContainsString('tt_content:31', $output);
    }

    #[Test]
    #[DataProvider('ambientUsers')]
    public function resolveUrlFollowsTheActingUsersMounts(string $ambient): void
    {
        $context = $this->actingEditorWith($ambient);
        $tool    = $this->tool('resolve_url');

        self::assertStringContainsString('Page: [2] Mounted', $tool->execute(['url' => '/mounted'], $context)->content);
        self::assertStringContainsString('Page not found or not permitted.', $tool->execute(['url' => '/unmounted'], $context)->content);
    }

    /**
     * The editor mounted on page 2 only, built WITHOUT staying the ambient user
     * — the shape `ActingBackendUserResolver` produces for a resumed run.
     */
    private function actingEditorWith(string $ambient): ToolExecutionContext
    {
        $editor = $this->setUpBackendUser(self::EDITOR);
        self::assertFalse($editor->isAdmin());
        $editor->groupData['tables_select']     = 'pages,tt_content';
        $editor->groupData['tables_modify']     = 'tt_content';
        $editor->groupData['webmounts']         = (string) self::MOUNTED_PAGE;
        $editor->groupData['allowed_languages'] = '0,1';

        if ($ambient === 'admin') {
            $admin = $this->setUpBackendUser(self::ADMIN);
            self::assertTrue($admin->isAdmin());
        } else {
            unset($GLOBALS['BE_USER']);
        }

        self::assertNotSame($editor, $GLOBALS['BE_USER'] ?? null);
        self::assertInstanceOf(BackendUserAuthentication::class, $editor);

        return ToolExecutionContext::fromBackendUser($editor);
    }

    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
