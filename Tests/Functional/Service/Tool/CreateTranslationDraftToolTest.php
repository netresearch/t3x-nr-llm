<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the fifth writing tool (ADR-146), against a real database,
 * the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler} and a real site
 * configuration.
 *
 * The site configuration is not scaffolding: core's
 * {@see \TYPO3\CMS\Core\DataHandling\DataHandler::localize()} resolves the
 * target language through it and refuses a language the site does not define.
 * The tool deliberately does not re-implement that check, so a test without a
 * site would prove the refusal rather than the translation.
 */
#[CoversClass(CreateTranslationDraftTool::class)]
final class CreateTranslationDraftToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const ROOT_PAGE = 1;

    private const CHILD_PAGE = 2;

    private const ELEMENT = 20;

    /** Defined by the site configuration below. */
    private const GERMAN = 1;

    /** NOT defined by the site configuration below. */
    private const UNDEFINED_LANGUAGE = 9;

    private CreateTranslationDraftTool $tool;

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
            'uid' => self::ROOT_PAGE, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1, 'is_siteroot' => 1, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid' => self::CHILD_PAGE, 'pid' => self::ROOT_PAGE, 'title' => 'Child', 'doktype' => 1,
            'slug' => '/child', 'sorting' => 1, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::CHILD_PAGE, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Original', 'bodytext' => 'Original body',
            'sys_language_uid' => 0, 'l18n_parent' => 0,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => self::ROOT_PAGE,
            'base'       => 'https://example.com/',
            'languages'  => [
                [
                    'languageId' => 0,
                    'title'      => 'English',
                    'base'       => '/',
                    'locale'     => 'en_US.UTF-8',
                    'flag'       => 'us',
                ],
                [
                    'languageId'   => self::GERMAN,
                    'title'        => 'German',
                    'base'         => '/de/',
                    'locale'       => 'de_DE.UTF-8',
                    'flag'         => 'de',
                    'fallbackType' => 'strict',
                ],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateTranslationDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aPageTranslationIsCreatedHiddenAndConnected(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden translation', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);

        $translation = $this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent');
        self::assertSame(self::GERMAN, (int)($translation['sys_language_uid'] ?? -1));
        self::assertSame(self::CHILD_PAGE, (int)($translation['l10n_parent'] ?? 0));
        self::assertSame(1, (int)($translation['hidden'] ?? 0), 'a drafted translation must never be visible');
        // The source is untouched, in particular still visible.
        self::assertSame(0, (int)($this->row('pages', self::CHILD_PAGE)['hidden'] ?? 1));
    }

    #[Test]
    public function aContentElementTranslationIsCreatedHiddenAndConnected(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $translation = $this->translationOf('tt_content', self::ELEMENT, 'l18n_parent');
        self::assertSame(self::GERMAN, (int)($translation['sys_language_uid'] ?? -1));
        self::assertSame(self::ELEMENT, (int)($translation['l18n_parent'] ?? 0));
        self::assertSame(1, (int)($translation['hidden'] ?? 0));
        // The content came across. Core prefixes the text fields of a fresh
        // translation with "[Translate to <language>:]" so an editor can see at
        // a glance what still needs work — the tool does not interfere with it.
        $header = $translation['header'] ?? '';
        $body   = $translation['bodytext'] ?? '';
        self::assertIsString($header);
        self::assertIsString($body);
        self::assertStringContainsString('Original', $header);
        self::assertStringContainsString('Translate to German', $header);
        self::assertStringContainsString('Original body', $body);
    }

    #[Test]
    public function anExistingTranslationStopsTheCallAndIsNamed(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $firstUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $second = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($second->isError);
        self::assertStringContainsString('already has a translation', $second->content);
        self::assertStringContainsString('uid ' . $firstUid, $second->content);
        self::assertStringContainsString('"overwrite": true', $second->content);
        // Still exactly one.
        self::assertSame($firstUid, (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid']);
    }

    #[Test]
    public function overwriteDiscardsTheOldTranslationAndCreatesAFreshOne(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $oldUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $second = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN, 'overwrite' => true],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($second->isError, $second->content);
        self::assertStringContainsString('replacing translation [' . $oldUid . ']', $second->content);

        // The old one is deleted rather than gone: recoverable, and in the log.
        self::assertSame(1, (int)($this->row('pages', $oldUid)['deleted'] ?? 0));

        $fresh = $this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent');
        self::assertNotSame($oldUid, (int)$fresh['uid']);
        self::assertSame(1, (int)($fresh['hidden'] ?? 0));
    }

    #[Test]
    public function aLanguageTheSiteDoesNotDefineIsRefusedByCoreAndSurfaced(): void
    {
        // The tool deliberately does not re-implement this check; core's
        // localize() does it and its complaint has to reach the caller.
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::UNDEFINED_LANGUAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertStringContainsString('9', $result->content);
    }

    #[Test]
    public function aSourceThatIsItselfATranslationIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $translationUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => $translationUid, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is itself a translation', $result->content);
    }

    #[Test]
    public function anEditorMayNotTranslateAPageTheyMayNotEdit(): void
    {
        $closed = $this->connectionPool->getConnectionForTable('pages');
        $closed->insert('pages', [
            'uid' => 5, 'pid' => self::ROOT_PAGE, 'title' => 'Closed', 'doktype' => 1, 'slug' => '/closed',
            'sorting' => 5, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);

        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'pages';

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 5, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertNull($this->maybeTranslationOf('pages', 5, 'l10n_parent'));
    }

    #[Test]
    public function thePreviewNamesTheDiscardOnItsOwnLine(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $oldUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $lines = $this->tool->previewCall(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN, 'overwrite' => true],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(4, $lines);
        self::assertStringContainsString('Translate pages [2] "Child" into language 1', $lines[0]);
        self::assertStringContainsString('DISCARDS the existing translation [' . $oldUid . ']', $lines[1]);
        self::assertStringContainsString('hidden', $lines[3]);

        // A preview is a read: the translation it says it would discard is
        // still there afterwards.
        self::assertSame(0, (int)($this->row('pages', $oldUid)['deleted'] ?? 1));
    }

    #[Test]
    public function thePreviewWithoutADiscardHasNoDiscardLine(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertStringNotContainsString('DISCARDS', $line);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function translationOf(string $table, int $uid, string $parentField): array
    {
        $row = $this->maybeTranslationOf($table, $uid, $parentField);
        self::assertIsArray($row, sprintf('%s [%d] must have a translation', $table, $uid));

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeTranslationOf(string $table, int $uid, string $parentField): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertLessThanOrEqual(1, count($rows), 'at most one undeleted translation may exist');

        return $rows[0] ?? null;
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

        self::assertIsArray($row, sprintf('%s [%d] must exist', $table, $uid));

        return $row;
    }
}
