<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
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
 * The write path of the fourth writing tool — the first that creates a record
 * (ADR-146), against a real database and the real
 * {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 *
 * The assertion this file exists for is that the element is HIDDEN. Everything
 * else about the tool is a narrowing of what an editor could do by hand; the
 * hidden state is the one property that makes a machine-drafted element safe,
 * and it must hold without any argument asking for it.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** A page only the admin may edit content on. */
    private const PAGE_CLOSED = 1;

    /** A page every backend user may edit content on. */
    private const PAGE_OPEN = 2;

    private const EXISTING_ELEMENT = 20;

    /** Defined by {@see self::defineSiteLanguages()}. */
    private const GERMAN = 1;

    /** Defined by {@see self::defineSiteLanguages()}. */
    private const FRENCH = 2;

    private CreateContentElementDraftTool $tool;

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
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $pages->insert('pages', [
            'uid' => self::PAGE_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 2,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::EXISTING_ELEMENT, 'pid' => self::PAGE_OPEN, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Already there', 'sys_language_uid' => 0,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateContentElementDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theCreatedElementIsHiddenEvenThoughNothingAskedForIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Drafted headline', 'bodytext' => '<p>Body.</p>'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden text element', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);

        $row = $this->createdElement();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted element must never be visible');
        self::assertSame(self::PAGE_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame('text', $row['CType'] ?? null);
        self::assertSame('Drafted headline', $row['header'] ?? null);
        self::assertSame(0, (int)($row['colPos'] ?? -1));
        self::assertSame(0, (int)($row['sys_language_uid'] ?? -1));
    }

    #[Test]
    public function theBodyIsOptionalAndTheColumnIsHonoured(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'header', 'header' => 'Just a headline', 'column' => 2],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdElement();
        self::assertSame('header', $row['CType'] ?? null);
        self::assertSame(2, (int)($row['colPos'] ?? -1));

        $body = $row['bodytext'] ?? '';
        self::assertIsString($body);
        self::assertSame('', $body);
    }

    #[Test]
    public function anAnchorPlacesTheElementAfterIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'              => self::PAGE_OPEN,
                'type'              => 'text',
                'header'            => 'After the existing one',
                'after_content_uid' => self::EXISTING_ELEMENT,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $created = $this->createdElement();
        self::assertSame(self::PAGE_OPEN, (int)($created['pid'] ?? 0), 'a negative pid must resolve to the page');
        self::assertGreaterThan(
            (int)$this->elementRow(self::EXISTING_ELEMENT)['sorting'],
            (int)($created['sorting'] ?? 0),
            'the new element must sort after its anchor',
        );
    }

    #[Test]
    public function theSysLogEntryNamesTheActingUser(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Logged'],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($result->isError, $result->content);

        $created = $this->createdElement();
        self::assertSame(
            [1],
            array_values(array_unique($this->sysLogUserIdsFor((int)($created['uid'] ?? 0)))),
        );
    }

    #[Test]
    public function anEditorMayNotCreateOnAPageTheyMayNotEdit(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        $result = $this->tool->execute(
            ['page' => self::PAGE_CLOSED, 'type' => 'text', 'header' => 'Sneaky'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(1, $this->elementCount(), 'nothing may have been created');
    }

    #[Test]
    public function anEditorCreatesOnAPageTheyMayEdit(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';
        // `tt_content.CType` is an `explicitAllow` field; without the grant the
        // DataHandler refuses the record, which is real backend behaviour.
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';
        // `hidden` is an exclude field. Without this grant the DataHandler
        // DROPS it silently and the element would be created visible — see
        // the test below, which is the reason this one has to state the grant.
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden';

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'By the editor'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->createdElement()['hidden'] ?? 0));
    }

    /**
     * The failure this tool must never leave behind: the DataHandler drops a
     * field the acting user has no "exclude field" grant for, silently and
     * without an errorLog entry. For `hidden` that would mean a machine-drafted
     * element live on the page.
     *
     * The read-back catches it and the element is taken back again, so the
     * refusal is the whole outcome rather than half of one.
     */
    #[Test]
    public function anElementThatCouldNotBeHiddenIsDeletedAgain(): void
    {
        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';
        // Deliberately WITHOUT `tt_content:hidden`.
        $editor->groupData['non_exclude_fields'] = 'tt_content:header';

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Would have been visible'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertStringContainsString('tt_content:hidden', $result->content);

        // Nothing undeleted is left on the page besides the fixture element.
        self::assertSame(1, $this->undeletedElementCount());
    }

    #[Test]
    public function anAnchorOnAnotherPageIsRefusedAndNothingIsCreated(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'              => self::PAGE_CLOSED,
                'type'              => 'text',
                'header'            => 'Misplaced',
                'after_content_uid' => self::EXISTING_ELEMENT,
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is not on page [1]', $result->content);
        self::assertSame(1, $this->elementCount());
    }

    #[Test]
    public function thePreviewShowsTheWholeDraftAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Proposed', 'bodytext' => 'Some body text.'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(5, $lines);
        self::assertStringContainsString('New text element on page [2] "Open"', $lines[0]);
        self::assertStringContainsString('"Proposed"', $lines[1]);
        self::assertStringContainsString('Some body text.', $lines[2]);
        self::assertStringContainsString('first in the column', $lines[3]);
        self::assertStringContainsString('hidden', $lines[4]);

        self::assertSame(1, $this->elementCount(), 'a preview must not create anything');
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);

        $arguments = ['page' => self::PAGE_CLOSED, 'type' => 'text', 'header' => 'x'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * The incident ADR-193 records: a free element in a language the page
     * already holds CONNECTED translations in makes the page module report
     * "Inconsistent content detected". Refused before anything is written.
     */
    #[Test]
    public function aFreeElementIsRefusedOnAPageHoldingAConnectedTranslationInThatLanguage(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringStartsWith('Refused: page [2] already holds connected translations in language 1', $result->content);
        self::assertStringContainsString('default language (0)', $result->content);
        self::assertStringContainsString('create_translation_draft', $result->content);
        self::assertSame(2, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * Core counts hidden rows when it judges a page's translation mode, and
     * every draft this extension writes is hidden.
     */
    #[Test]
    public function aHiddenConnectedTranslationRefusesTheFreeElementToo(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT, ['hidden' => 1]);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('already holds connected translations in language 1', $result->content);
        self::assertSame(2, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * A connected translation that so far exists only as another workspace's
     * draft counts as well: publishing that workspace would mix the page.
     */
    #[Test]
    public function aConnectedTranslationDraftedInAWorkspaceRefusesTheFreeElementToo(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT, ['t3ver_wsid' => 1, 't3ver_state' => 1]);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('already holds connected translations in language 1', $result->content);
        self::assertSame(2, $this->elementCount(), 'nothing may have been created');
    }

    #[Test]
    public function thePreviewShowsTheRefusalAndTheViewerGateFollowsIt(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT);

        $arguments = ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN];

        $lines = $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));

        self::assertCount(1, $lines);
        self::assertStringStartsWith('Refused: page [2] already holds connected translations in language 1', $lines[0]);
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $admin));
    }

    /**
     * The refusal names the page, so it must come after the neutral one: a user
     * who may not edit the page learns nothing about what is on it.
     */
    #[Test]
    public function theRefusalDoesNotDescribeAPageTheUserMayNotEdit(): void
    {
        $editor = $this->setUpBackendUser(2);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, 0, ['pid' => self::PAGE_CLOSED]);
        $this->insertElement(22, self::GERMAN, 21, ['pid' => self::PAGE_CLOSED]);

        $result = $this->tool->execute(
            ['page' => self::PAGE_CLOSED, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    /**
     * Free mode stays legitimate: a page without any element in the language
     * takes a standalone one, in the language asked for.
     */
    #[Test]
    public function aLanguageThePageHoldsNothingInIsStillAllowed(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdElement();
        self::assertSame(self::GERMAN, (int)($row['sys_language_uid'] ?? -1));
        self::assertSame(0, (int)($row['l18n_parent'] ?? -1));
    }

    /**
     * A page that only ever holds free elements in a language is consistent,
     * so one more of them is not refused.
     */
    #[Test]
    public function aPageHoldingOnlyFreeElementsInThatLanguageTakesAnotherOne(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, 0);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Noch eines', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::GERMAN, (int)($this->createdElement(21)['sys_language_uid'] ?? -1));
    }

    /**
     * The integrator's opt-in, the same switch that silences core's warning.
     */
    #[Test]
    public function allowInconsistentLanguageHandlingInPageTsConfigLiftsTheRefusal(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT);
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'mod.web_layout.allowInconsistentLanguageHandling = 1'],
            ['uid' => self::PAGE_OPEN],
        );

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::GERMAN, (int)($this->createdElement(21)['sys_language_uid'] ?? -1));
    }

    /**
     * Core never judges the default language — `getTranslationData()` returns
     * early for it — so not even a malformed default-language row carrying a
     * translation parent refuses an element there.
     */
    #[Test]
    public function theDefaultLanguageIsUnaffectedOnThePageThatRefusesAnotherLanguage(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT);
        $this->insertElement(22, 0, self::EXISTING_ELEMENT);

        $refused = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertTrue($refused->isError, 'the fixture must be a page that refuses language 1');

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Default language'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, (int)($this->createdElement(21, 22)['sys_language_uid'] ?? -1));
    }

    #[Test]
    public function aConnectedTranslationInAnotherLanguageDoesNotRefuseThisOne(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::FRENCH, self::EXISTING_ELEMENT);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::GERMAN, (int)($this->createdElement(21)['sys_language_uid'] ?? -1));
    }

    #[Test]
    public function aConnectedTranslationOnAnotherPageDoesNotRefuseThisOne(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, 0, 0, ['pid' => self::PAGE_CLOSED]);
        $this->insertElement(22, self::GERMAN, 21, ['pid' => self::PAGE_CLOSED]);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::PAGE_OPEN, (int)($this->createdElement(21, 22)['pid'] ?? 0));
    }

    /**
     * Core excludes deleted rows when it judges the mode, so a translation
     * somebody removed no longer makes the page a connected one.
     */
    #[Test]
    public function aDeletedConnectedTranslationDoesNotRefuse(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->defineSiteLanguages();
        $this->insertElement(21, self::GERMAN, self::EXISTING_ELEMENT, ['deleted' => 1]);

        $result = $this->tool->execute(
            ['page' => self::PAGE_OPEN, 'type' => 'text', 'header' => 'Frei', 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::GERMAN, (int)($this->createdElement(21)['sys_language_uid'] ?? -1));
    }

    /**
     * A site with two translation languages on the open page. The DataHandler
     * resolves a record's language through the site, so the tests that create
     * an element in language 1 need one.
     */
    private function defineSiteLanguages(): void
    {
        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => self::PAGE_OPEN,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
                ['languageId' => self::GERMAN, 'title' => 'German', 'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de'],
                ['languageId' => self::FRENCH, 'title' => 'French', 'base' => '/fr/', 'locale' => 'fr_FR.UTF-8', 'flag' => 'fr'],
            ],
        ]);
    }

    /**
     * One more content element on the open page, written past the DataHandler.
     *
     * @param int                  $parent the l18n_parent: zero for a free element, a uid for a connected translation
     * @param array<string, mixed> $fields columns that differ from the defaults
     */
    private function insertElement(int $uid, int $language, int $parent, array $fields = []): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', $fields + [
            'uid' => $uid, 'pid' => self::PAGE_OPEN, 'colPos' => 0, 'sorting' => $uid,
            'CType' => 'text', 'header' => 'Fixture ' . $uid,
            'sys_language_uid' => $language, 'l18n_parent' => $parent, 'l10n_source' => $parent,
        ]);
    }

    /**
     * The one element this tool created, asserting there is exactly one.
     *
     * @param int ...$fixtureUids elements a test inserted itself, besides the one from setUp()
     *
     * @return array<string, mixed>
     */
    private function createdElement(int ...$fixtureUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->notIn(
                'uid',
                $queryBuilder->createNamedParameter([self::EXISTING_ELEMENT, ...$fixtureUids], Connection::PARAM_INT_ARRAY),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one element must have been created');

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function elementRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    private function elementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Elements that are not flagged deleted — what an editor would still see.
     */
    private function undeletedElementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<int>
     */
    private function sysLogUserIdsFor(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
