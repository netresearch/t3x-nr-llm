<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\EventListener\MarkAiWrittenRecord;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DeleteRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MovePageTool;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixtures\Translation\RecordingTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The structural writers in the draft workspace a run was started in
 * (ADR-266): a move, a copy, a delete and a translation are core's commands
 * there — a move version, records new in the workspace, a delete version —
 * and live stays as it is. Pages, elements and anchors are taken as that
 * workspace shows them, another workspace's drafts never.
 *
 * Every expectation about the stored rows was measured against core first,
 * on TYPO3 14.3.10 and 13.4.37, SQLite and MariaDB.
 */
#[CoversClass(MoveContentElementTool::class)]
#[CoversClass(MovePageTool::class)]
#[CoversClass(CopyRecordTool::class)]
#[CoversClass(DeleteRecordTool::class)]
#[CoversClass(CreateTranslationDraftTool::class)]
final class StructureWritersInTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const ROOT = 1;

    private const PAGE_A = 2;

    private const PAGE_B = 3;

    private const SUBPAGE = 4;

    private const PAGE_A_GERMAN = 5;

    private const ELEMENT = 10;

    private const SECOND = 11;

    private const ON_B = 12;

    private const ELEMENT_GERMAN = 14;

    private const GERMAN = 1;

    private const FRENCH = 2;

    private const WORKSPACE_LINE = 'Workspace: “Entwurf” — saved there as a draft';

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $workspaces = $this->connectionPool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => '']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);

        foreach ([
            [self::ROOT, 0, 'Root', 1, 0, 0],
            [self::PAGE_A, self::ROOT, 'A', 1, 0, 0],
            [self::PAGE_B, self::ROOT, 'B', 2, 0, 0],
            [self::SUBPAGE, self::PAGE_A, 'A sub', 1, 0, 0],
            [self::PAGE_A_GERMAN, self::ROOT, 'A (de)', 1, self::GERMAN, self::PAGE_A],
        ] as [$uid, $pid, $title, $sorting, $language, $parent]) {
            $this->insertPage(['uid' => $uid, 'pid' => $pid, 'title' => $title, 'sorting' => $sorting * 256, 'sys_language_uid' => $language, 'l10n_parent' => $parent, 'is_siteroot' => $uid === self::ROOT ? 1 : 0]);
        }

        foreach ([
            [self::ELEMENT, self::PAGE_A, 'E10', 256, 0, 0],
            [self::SECOND, self::PAGE_A, 'E11', 512, 0, 0],
            [self::ON_B, self::PAGE_B, 'E12', 256, 0, 0],
            [13, self::SUBPAGE, 'E13', 256, 0, 0],
            [self::ELEMENT_GERMAN, self::PAGE_A, 'E10 de', 256, self::GERMAN, self::ELEMENT],
        ] as [$uid, $pid, $header, $sorting, $language, $parent]) {
            $this->insertElement(['uid' => $uid, 'pid' => $pid, 'header' => $header, 'sorting' => $sorting, 'sys_language_uid' => $language, 'l18n_parent' => $parent]);
        }

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => self::ROOT,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
                ['languageId' => self::GERMAN, 'title' => 'German', 'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de'],
                ['languageId' => self::FRENCH, 'title' => 'French', 'base' => '/fr/', 'locale' => 'fr_FR.UTF-8', 'flag' => 'fr'],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ move_content_element

    #[Test]
    public function aMovedElementIsAMoveVersionInTheDraftWorkspaceAndLiveStays(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $call    = ['uid' => self::ELEMENT, 'target_page' => self::PAGE_B, 'column' => 2];

        self::assertStringContainsString(self::WORKSPACE_LINE, $this->card('move_content_element', $call, $context));

        $result = $this->tool('move_content_element')->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $this->assertLive('tt_content', self::ELEMENT, ['pid' => self::PAGE_A, 'colPos' => 0]);
        $version = $this->versionOf('tt_content', self::ELEMENT, self::DRAFT);
        self::assertSame([self::PAGE_B, 2, 4], [(int) $version['pid'], (int) $version['colPos'], (int) $version['t3ver_state']]);
        // Core moves the translation along, as a move version too.
        $translation = $this->versionOf('tt_content', self::ELEMENT_GERMAN, self::DRAFT);
        self::assertSame([self::PAGE_B, 4], [(int) $translation['pid'], (int) $translation['t3ver_state']]);
    }

    /**
     * "After element X" means X where the workspace shows it: an anchor
     * moved there to another page anchors on that page, and is no longer on
     * its live page.
     */
    #[Test]
    public function theAnchorIsWhereTheDraftWorkspaceShowsIt(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::SUBPAGE, 'header' => 'E12', 'sorting' => 1024, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ON_B, 't3ver_state' => 4]);
        $tool    = $this->tool('move_content_element');
        $context = $this->contextIn(self::DRAFT);

        $refused = $tool->execute(['uid' => self::ELEMENT, 'target_page' => self::PAGE_B, 'after_content_uid' => self::ON_B], $context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('is not on page [3]', $refused->content);

        $result = $tool->execute(['uid' => self::ELEMENT, 'target_page' => self::SUBPAGE, 'after_content_uid' => self::ON_B], $context);
        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::SUBPAGE, (int) $this->versionOf('tt_content', self::ELEMENT, self::DRAFT)['pid']);
        $this->assertLive('tt_content', self::ELEMENT, ['pid' => self::PAGE_A]);
    }

    #[Test]
    public function anElementMovesOntoAPageNewInTheDraftWorkspace(): void
    {
        $this->insertPage(['uid' => 30, 'pid' => self::ROOT, 'title' => 'New', 'sorting' => 4096, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);

        $result = $this->tool('move_content_element')->execute(['uid' => self::ELEMENT, 'target_page' => 30], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(30, (int) $this->versionOf('tt_content', self::ELEMENT, self::DRAFT)['pid']);
    }

    #[Test]
    public function anotherWorkspacesPagesAndDraftsAreNotThere(): void
    {
        $this->insertPage(['uid' => 30, 'pid' => self::ROOT, 'title' => 'New', 'sorting' => 4096, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E10 draft', 'sorting' => 256, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT]);
        $tool    = $this->tool('move_content_element');
        $context = $this->contextIn(self::OTHER_DRAFT);

        foreach ([['uid' => self::ELEMENT, 'target_page' => 30], ['uid' => 20, 'target_page' => self::PAGE_B]] as $call) {
            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, json_encode($call, JSON_THROW_ON_ERROR));
            self::assertSame('Content element not found or not permitted.', $result->content);
        }

        self::assertSame(0, $this->rowCount('tt_content', ['t3ver_wsid' => self::OTHER_DRAFT]));
    }

    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceMovesNothingInADraftWorkspace(): void
    {
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        $call    = ['uid' => self::ELEMENT, 'target_page' => self::PAGE_B];

        foreach (['move_content_element' => $call, 'move_page' => ['uid' => self::PAGE_A, 'parent' => self::PAGE_B], 'copy_record' => ['table' => 'pages', 'uid' => self::PAGE_A, 'target_page' => self::PAGE_B], 'delete_record' => ['table' => 'tt_content', 'uid' => self::ELEMENT]] as $name => $arguments) {
            self::assertStringContainsString('does not record which workspace', $this->refusalBeforeApproval($name, $arguments, $context), $name);
        }

        $card = $this->card('move_content_element', $call, $context);
        self::assertStringContainsString('does not record which workspace', $card);
        self::assertStringNotContainsString('Workspace:', $card);

        $result = $this->tool('move_content_element')->execute($call, $context);
        self::assertTrue($result->isError);
        self::assertStringContainsString('does not record which workspace', $result->content);
        self::assertSame(0, $this->rowCount('tt_content', ['t3ver_wsid' => self::DRAFT]));
    }

    // ------------------------------------------------------------------ move_page

    #[Test]
    public function aMovedPageIsAMoveVersionWithItsTranslation(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $call    = ['uid' => self::PAGE_A, 'parent' => self::PAGE_B];

        self::assertStringContainsString(self::WORKSPACE_LINE, $this->card('move_page', $call, $context));

        $result = $this->tool('move_page')->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $this->assertLive('pages', self::PAGE_A, ['pid' => self::ROOT]);
        $version = $this->versionOf('pages', self::PAGE_A, self::DRAFT);
        self::assertSame([self::PAGE_B, 4], [(int) $version['pid'], (int) $version['t3ver_state']]);
        self::assertSame(self::PAGE_B, (int) $this->versionOf('pages', self::PAGE_A_GERMAN, self::DRAFT)['pid']);
    }

    /**
     * The read-back finds the page after its anchor among the siblings the
     * workspace shows: the page's own move version sorts as the page.
     */
    #[Test]
    public function aPageMovedAfterASiblingReadsBackInTheDraftWorkspace(): void
    {
        $result = $this->tool('move_page')->execute(['uid' => self::PAGE_A, 'after_page_uid' => self::PAGE_B], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        $this->assertLive('pages', self::PAGE_A, ['sorting' => 256]);
        self::assertGreaterThan(512, (int) $this->versionOf('pages', self::PAGE_A, self::DRAFT)['sorting']);
    }

    /**
     * A sibling new in the workspace anchors the move, and the read-back
     * finds the page right after it among the siblings the workspace shows.
     */
    #[Test]
    public function aPageMovesAfterASiblingNewInTheDraftWorkspace(): void
    {
        $this->insertPage(['uid' => 30, 'pid' => self::ROOT, 'title' => 'New', 'sorting' => 2048, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);

        $result = $this->tool('move_page')->execute(['uid' => self::PAGE_A, 'after_page_uid' => 30], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        self::assertGreaterThan(2048, (int) $this->versionOf('pages', self::PAGE_A, self::DRAFT)['sorting']);
    }

    /**
     * Core points the move version of a translation at the move version of
     * its page: a writer acting on the page afterwards still finds the
     * translation there.
     */
    #[Test]
    public function aPageMovedInTheDraftWorkspaceKeepsItsTranslationForTheNextCall(): void
    {
        $context = $this->contextIn(self::DRAFT);
        self::assertFalse($this->tool('move_page')->execute(['uid' => self::PAGE_A, 'parent' => self::PAGE_B], $context)->isError);
        self::assertSame(
            (int) $this->versionOf('pages', self::PAGE_A, self::DRAFT)['uid'],
            (int) $this->versionOf('pages', self::PAGE_A_GERMAN, self::DRAFT)['l10n_parent'],
            'positive control: core points the translation version at the page version',
        );

        self::assertStringContainsString('translations: 1;', $this->card('move_page', ['uid' => self::PAGE_A, 'parent' => self::ROOT], $context));
    }

    /**
     * A page moved in the workspace under another one lies in that one's
     * branch there: the target is refused as core would refuse it.
     */
    #[Test]
    public function theBranchCheckWalksTheTreeTheDraftWorkspaceShows(): void
    {
        $this->insertPage(['uid' => 40, 'pid' => self::PAGE_A, 'title' => 'B', 'sorting' => 512, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::PAGE_B, 't3ver_state' => 4]);

        $result = $this->tool('move_page')->execute(['uid' => self::PAGE_A, 'parent' => self::PAGE_B], $this->contextIn(self::DRAFT));

        self::assertTrue($result->isError);
        self::assertStringContainsString('lies inside the branch of page [2]', $result->content);
        self::assertNull($this->maybeVersionOf('pages', self::PAGE_A, self::DRAFT));
    }

    // ------------------------------------------------------------------ copy_record

    #[Test]
    public function aCopyIsNewInTheDraftWorkspaceAndCarriesTheDraftsText(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E11 in the draft', 'sorting' => 512, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::SECOND]);
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tt_content', 'uid' => self::SECOND, 'target_page' => self::PAGE_B, 'column' => 1];
        $live    = $this->rowCount('tt_content', ['t3ver_wsid' => 0]);

        $card = $this->card('copy_record', $call, $context);
        self::assertStringContainsString(self::WORKSPACE_LINE, $card);
        self::assertStringContainsString('E11 in the draft', $card);

        $result = $this->tool('copy_record')->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertSame($live, $this->rowCount('tt_content', ['t3ver_wsid' => 0]), 'a live row was written');
        $copy = $this->newIn('tt_content', self::DRAFT, self::PAGE_B);
        self::assertSame(['E11 in the draft', 1, 1, 1], [self::stringOf($copy['header']), (int) $copy['colPos'], (int) $copy['hidden'], (int) $copy['t3ver_state']]);
    }

    #[Test]
    public function aCopiedPageCountsTheContentTheDraftWorkspaceShows(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E11', 'sorting' => 512, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::SECOND, 't3ver_state' => 2]);
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'pages', 'uid' => self::PAGE_A, 'target_page' => self::PAGE_B];

        // E11 is deleted in the workspace, and core copies no translation of
        // an element onto the page new there: E10 alone.
        self::assertStringContainsString('Copied with it: content elements: 1,', $this->card('copy_record', $call, $context));
        self::assertStringContainsString('Copied with it: content elements: 3,', $this->card('copy_record', $call, $this->contextIn(0)));

        $result = $this->tool('copy_record')->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        // The page and its translation, both new in the workspace and hidden.
        $copies = $this->rows('pages', ['t3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 'pid' => self::PAGE_B, 'deleted' => 0]);
        self::assertSame([[0, 1, 1], [self::GERMAN, 1, 1]], array_map(static fn (array $row): array => [(int) $row['sys_language_uid'], (int) $row['hidden'], (int) $row['t3ver_state']], $copies));
        // What the card counted: the element the workspace shows, copied new
        // there; not the one deleted there, not the element's translation.
        self::assertSame(['E10'], array_map(static fn (array $row): string => self::stringOf($row['header']), $this->rows('tt_content', ['pid' => (int) $copies[0]['uid'], 'deleted' => 0])));
        self::assertSame(0, $this->rowCount('pages', ['t3ver_wsid' => 0, 'pid' => self::PAGE_B]));
    }

    // ------------------------------------------------------------------ delete_record

    #[Test]
    public function aDeleteMarksTheElementAndItsTranslationInTheDraftWorkspace(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tt_content', 'uid' => self::ELEMENT];

        self::assertSame('', $this->refusalBeforeApproval('delete_record', $call, $context), 'an ordinary delete waits for approval');
        self::assertSame('', $this->refusalBeforeApproval('move_content_element', ['uid' => self::ELEMENT, 'target_page' => self::PAGE_B], $context));
        $card = $this->card('delete_record', $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf” — marked for deletion there', $card);
        self::assertStringNotContainsString('recycler', $card);

        $result = $this->tool('delete_record')->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Marked tt_content [10] "E10" and its 1 translation(s) for deletion in the workspace "Entwurf"', $result->content);
        $this->assertLive('tt_content', self::ELEMENT, ['deleted' => 0]);
        $this->assertLive('tt_content', self::ELEMENT_GERMAN, ['deleted' => 0]);
        self::assertSame(2, (int) $this->versionOf('tt_content', self::ELEMENT, self::DRAFT)['t3ver_state']);
        self::assertSame(2, (int) $this->versionOf('tt_content', self::ELEMENT_GERMAN, self::DRAFT)['t3ver_state']);
    }

    /**
     * The element's own change in the run's workspace is not another
     * workspace's draft: core turns it into the delete version.
     */
    #[Test]
    public function anElementChangedInTheDraftWorkspaceIsMarkedThere(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E10 changed', 'sorting' => 256, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT]);

        $result = $this->tool('delete_record')->execute(['table' => 'tt_content', 'uid' => self::ELEMENT], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(2, (int) $this->row('tt_content', 20)['t3ver_state']);
    }

    #[Test]
    public function anotherWorkspacesDraftStillStopsADeleteInTheDraftWorkspace(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E10 other', 'sorting' => 256, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => self::ELEMENT]);

        $result = $this->tool('delete_record')->execute(['table' => 'tt_content', 'uid' => self::ELEMENT], $this->contextIn(self::DRAFT));

        self::assertTrue($result->isError);
        self::assertStringContainsString('would take along 1 workspace draft(s)', $result->content);
        self::assertNull($this->maybeVersionOf('tt_content', self::ELEMENT, self::DRAFT));
    }

    /**
     * Core's delete discards a move made in the workspace instead of deleting
     * the element, and discards a record new there for good: refused before
     * the card, with nothing written.
     */
    #[Test]
    public function anElementMovedOrNewInTheDraftWorkspaceIsRefusedBeforeTheCard(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_B, 'header' => 'E10', 'sorting' => 1024, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT, 't3ver_state' => 4]);
        $this->insertElement(['uid' => 21, 'pid' => self::PAGE_A, 'header' => 'New', 'sorting' => 1024, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);
        $context = $this->contextIn(self::DRAFT);

        foreach ([self::ELEMENT => 'was moved in the workspace "Entwurf"', 21 => 'is new in the workspace "Entwurf"'] as $uid => $reason) {
            $call = ['table' => 'tt_content', 'uid' => $uid];
            self::assertStringContainsString($reason, $this->refusalBeforeApproval('delete_record', $call, $context));
            $card = $this->card('delete_record', $call, $context);
            self::assertStringContainsString($reason, $card);
            self::assertStringNotContainsString('Workspace:', $card);

            $result = $this->tool('delete_record')->execute($call, $context);
            self::assertTrue($result->isError, (string) $uid);
            self::assertStringContainsString($reason, $result->content);
        }

        self::assertSame(4, (int) $this->row('tt_content', 20)['t3ver_state']);
        self::assertSame(0, (int) $this->row('tt_content', 21)['deleted']);
    }

    /**
     * Core deletes each translation as it deletes the record: a translation
     * new in the workspace would be discarded for good.
     */
    #[Test]
    public function anElementWhoseTranslationIsNewInTheDraftWorkspaceIsRefusedBeforeTheCard(): void
    {
        $this->insertElement(['uid' => 21, 'pid' => self::PAGE_A, 'header' => 'E11 de', 'sorting' => 1024, 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::SECOND, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);
        $call = ['table' => 'tt_content', 'uid' => self::SECOND];

        self::assertStringContainsString(
            'the translation tt_content [21] of tt_content [11] is new in the workspace "Entwurf"',
            $this->refusalBeforeApproval('delete_record', $call, $this->contextIn(self::DRAFT)),
        );
        self::assertTrue($this->tool('delete_record')->execute($call, $this->contextIn(self::DRAFT))->isError);
        self::assertSame(0, (int) $this->row('tt_content', 21)['deleted']);
        self::assertNull($this->maybeVersionOf('tt_content', self::SECOND, self::DRAFT));
    }

    /**
     * A page is marked with its translation; its subpages and their content
     * get no version of their own and are deleted live only when the
     * workspace is published, so the read-back does not ask for them.
     */
    #[Test]
    public function aPageAndItsTranslationAreMarkedAndTheBranchStaysUntilPublished(): void
    {
        $result = $this->tool('delete_record')->execute(['table' => 'pages', 'uid' => self::PAGE_A, 'include_subpages' => true], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        self::assertStringNotContainsString('not completely', $result->content);
        self::assertSame(2, (int) $this->versionOf('pages', self::PAGE_A, self::DRAFT)['t3ver_state']);
        self::assertSame(2, (int) $this->versionOf('pages', self::PAGE_A_GERMAN, self::DRAFT)['t3ver_state']);
        self::assertNull($this->maybeVersionOf('pages', self::SUBPAGE, self::DRAFT));
        $this->assertLive('pages', self::PAGE_A, ['deleted' => 0]);
        $this->assertLive('pages', self::SUBPAGE, ['deleted' => 0]);
    }

    // ------------------------------------------------------------------ create_translation_draft

    #[Test]
    public function aTranslationIsNewInTheDraftWorkspaceAndCarriesTheMark(): void
    {
        $this->insertElement(['uid' => 20, 'pid' => self::PAGE_A, 'header' => 'E11 in the draft', 'sorting' => 512, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::SECOND]);
        $tool    = $this->translationTool();
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tt_content', 'uid' => self::SECOND, 'language' => self::GERMAN];

        self::assertStringContainsString(self::WORKSPACE_LINE, implode("\n", $tool->previewCall($call, $context)));
        self::assertNull($tool->refusalBeforeApproval($call, $context));

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertSame(0, $this->rowCount('tt_content', ['t3ver_wsid' => 0, 'l18n_parent' => self::SECOND]), 'a live translation was written');
        $translation = $this->newIn('tt_content', self::DRAFT, self::PAGE_A);
        self::assertSame(['[de] E11 in the draft', 1, self::SECOND], [self::stringOf($translation['header']), (int) $translation['hidden'], (int) $translation['l18n_parent']]);

        // The provenance mark goes on the row new in the workspace.
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertSame((int) $translation['uid'], $result->writeTarget->uid);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        $this->mark($result, self::DRAFT);
        self::assertSame(1, (int) $this->row('tt_content', (int) $translation['uid'])[MarkAiWrittenRecord::FIELD]);
        self::assertSame(0, (int) $this->row('tt_content', self::SECOND)[MarkAiWrittenRecord::FIELD]);
    }

    /**
     * Overwriting deletes the existing translation and localizes afresh. In a
     * draft workspace TYPO3 13.4 refuses that localization after the delete:
     * refused before the card on both cores.
     */
    #[Test]
    public function anOverwriteIsRefusedBeforeTheCardInADraftWorkspace(): void
    {
        $tool    = $this->translationTool();
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN, 'overwrite' => true];

        self::assertStringContainsString('"overwrite" is not available in a draft workspace', (string) $tool->refusalBeforeApproval($call, $context)?->content);
        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('"overwrite" is not available in a draft workspace', $card);
        self::assertStringNotContainsString('Workspace:', $card);

        $result = $tool->execute($call, $context);
        self::assertTrue($result->isError);
        self::assertStringContainsString('"overwrite" is not available in a draft workspace', $result->content);
        self::assertSame(0, $this->rowCount('tt_content', ['t3ver_wsid' => self::DRAFT]));
    }

    /**
     * A translation that exists only in the workspace stops a second one
     * there, as core's localize would.
     */
    #[Test]
    public function aTranslationNewInTheDraftWorkspaceIsTheExistingOne(): void
    {
        $this->insertElement(['uid' => 21, 'pid' => self::PAGE_A, 'header' => 'E11 fr', 'sorting' => 1024, 'sys_language_uid' => self::FRENCH, 'l18n_parent' => self::SECOND, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);

        $result = $this->translationTool()->execute(['table' => 'tt_content', 'uid' => self::SECOND, 'language' => self::FRENCH], $this->contextIn(self::DRAFT));

        self::assertTrue($result->isError);
        self::assertStringContainsString('already has a translation in language 2 (uid 21)', $result->content);
        self::assertFalse($this->translationTool()->execute(['table' => 'tt_content', 'uid' => self::SECOND, 'language' => self::FRENCH], $this->contextIn(0))->isError, 'live does not see the draft translation');
    }

    /**
     * A limit, pinned so a change of core's shows (ADR-266): core's site
     * lookup reads the live rootline, so a page new in the workspace belongs
     * to no site, and its record cannot be translated there.
     */
    #[Test]
    public function aRecordOnAPageNewInTheDraftWorkspaceIsRefusedForWantOfASite(): void
    {
        $this->insertPage(['uid' => 30, 'pid' => self::ROOT, 'title' => 'New', 'sorting' => 4096, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);
        $this->insertElement(['uid' => 31, 'pid' => 30, 'header' => 'New element', 'sorting' => 256, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);

        $result = $this->translationTool()->execute(['table' => 'tt_content', 'uid' => 31, 'language' => self::GERMAN], $this->contextIn(self::DRAFT));

        self::assertTrue($result->isError);
        self::assertStringContainsString('page [30] belongs to no site', $result->content);
    }

    // ------------------------------------------------------------------ every writer

    /**
     * Where an installation takes the versioning off a table — or keeps it
     * but lets the table be edited live in a workspace, which TYPO3 13.4
     * honours for a versioned table too — core keeps no draft: the call is
     * refused before the card (ADR-262), for the table each writer writes.
     */
    #[Test]
    public function aTableCoreKeepsNoDraftOfIsRefusedBeforeTheCard(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $calls   = [
            'tt_content' => [
                'move_content_element'     => ['uid' => self::ELEMENT, 'target_page' => self::PAGE_B],
                'copy_record'              => ['table' => 'tt_content', 'uid' => self::ELEMENT, 'target_page' => self::PAGE_B],
                'delete_record'            => ['table' => 'tt_content', 'uid' => self::ELEMENT],
                'create_translation_draft' => ['table' => 'tt_content', 'uid' => self::SECOND, 'language' => self::FRENCH],
            ],
            'pages' => [
                'move_page'                => ['uid' => self::PAGE_A, 'parent' => self::PAGE_B],
                'copy_record'              => ['table' => 'pages', 'uid' => self::PAGE_A, 'target_page' => self::PAGE_B],
                'delete_record'            => ['table' => 'pages', 'uid' => self::PAGE_B],
                'create_translation_draft' => ['table' => 'pages', 'uid' => self::PAGE_B, 'language' => self::GERMAN],
            ],
        ];
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);

        foreach ($calls as $table => $byTool) {
            // Positive control: with the versioning in place, nothing refuses.
            foreach ($byTool as $name => $call) {
                self::assertSame('', $this->refusalBeforeApproval($name, $call, $context), $name . ' on ' . $table);
            }

            $definition = $tca[$table] ?? null;
            self::assertIsArray($definition);
            $ctrl = $definition['ctrl'] ?? null;
            self::assertIsArray($ctrl);
            $ctrl['versioningWS_alwaysAllowLiveEdit'] = true;
            $definition['ctrl']                       = $ctrl;
            $GLOBALS['TCA']                           = [$table => $definition] + $tca;
            try {
                foreach ($byTool as $name => $call) {
                    self::assertStringContainsString('TYPO3 keeps no draft of ' . $table . ' records', $this->refusalBeforeApproval($name, $call, $context), $name);
                }
            } finally {
                $GLOBALS['TCA'] = $tca;
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function contextIn(int $workspace): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->adminIn($workspace));
    }

    private function adminIn(int $workspace): BackendUserAuthentication
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $admin->workspace, 'positive control: the user is in the workspace');

        return $admin;
    }

    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($name, true);

        return $tool;
    }

    /**
     * @param array<string, mixed> $call
     */
    private function card(string $name, array $call, ToolExecutionContext $context): string
    {
        $tool = $this->tool($name);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        return implode("\n", $tool->previewCall($call, $context));
    }

    /**
     * The tool's refusal before any approval, '' where the call waits for one.
     *
     * @param array<string, mixed> $call
     */
    private function refusalBeforeApproval(string $name, array $call, ToolExecutionContext $context): string
    {
        $tool = $name === 'create_translation_draft' ? $this->translationTool() : $this->tool($name);
        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);

        return $tool->refusalBeforeApproval($call, $context)->content ?? '';
    }

    private function translationTool(): CreateTranslationDraftTool
    {
        $translators = ['llm' => new RecordingTranslator('llm', 'Language model')];
        $registry    = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $identifier): TranslatorInterface => $translators[$identifier]
                ?? throw new ServiceUnavailableException('No translator ' . $identifier, 'translation'),
        );
        $registry->method('has')->willReturnCallback(static fn (string $identifier): bool => isset($translators[$identifier]));

        $typo3Caches = new Typo3CacheManager();
        $typo3Caches->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);

        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);

        return new CreateTranslationDraftTool(
            $this->connectionPool,
            new TranslationService(
                self::createStub(LlmServiceManagerInterface::class),
                $registry,
                self::createStub(LlmConfigurationServiceInterface::class),
                new TranslationPromptBuilder(),
                null,
                new GlossaryResolver($this->connectionPool),
                null,
                new CacheManager($typo3Caches),
            ),
            $siteFinder,
            new GlossaryResolver($this->connectionPool),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        );
    }

    /**
     * What the tool loop does with a write target (ADR-239): the provenance
     * listener, told the workspace the run wrote in.
     */
    private function mark(ToolResult $result, int $workspace): void
    {
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertInstanceOf(WriteKind::class, $result->writeKind);
        (new MarkAiWrittenRecord($this->connectionPool))(new AfterAiRecordWrittenEvent('run-1', $result->writeTarget, $result->writeKind, $workspace));
    }

    /**
     * @param array<string, int|string> $values
     */
    private function insertPage(array $values): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', $values + [
            'doktype'      => 1, 'slug' => '/p' . self::stringOf($values['uid']),
            'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
        ]);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function insertElement(array $values): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', $values + ['CType' => 'text', 'colPos' => 0]);
    }

    /**
     * @param array<string, int> $expected
     */
    private function assertLive(string $table, int $uid, array $expected): void
    {
        $row = $this->row($table, $uid);
        self::assertSame(0, (int) $row['t3ver_wsid'], 'not a live row');
        foreach ($expected as $column => $value) {
            self::assertSame($value, (int) $row[$column], sprintf('live %s [%d] %s changed', $table, $uid, $column));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        $row = $this->rows($table, ['uid' => $uid])[0] ?? null;
        self::assertIsArray($row, sprintf('%s [%d] must exist', $table, $uid));

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionOf(string $table, int $uid, int $workspace): array
    {
        $version = $this->maybeVersionOf($table, $uid, $workspace);
        self::assertIsArray($version, sprintf('%s [%d] has no version in workspace %d', $table, $uid, $workspace));

        return $version;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeVersionOf(string $table, int $uid, int $workspace): ?array
    {
        return $this->rows($table, ['t3ver_oid' => $uid, 't3ver_wsid' => $workspace, 'deleted' => 0])[0] ?? null;
    }

    /**
     * The one undeleted record new in the workspace on a page.
     *
     * @return array<string, mixed>
     */
    private function newIn(string $table, int $workspace, int $pid): array
    {
        $rows = $this->rows($table, ['t3ver_wsid' => $workspace, 't3ver_oid' => 0, 'pid' => $pid, 'deleted' => 0]);
        self::assertCount(1, $rows, sprintf('one %s record new in workspace %d on page %d', $table, $workspace, $pid));

        return $rows[0];
    }

    /**
     * @param array<string, int> $where
     */
    private function rowCount(string $table, array $where): int
    {
        return count($this->rows($table, $where + ['deleted' => 0]));
    }

    /**
     * Every row matching, whatever the workspace, hidden or not.
     *
     * @param array<string, int> $where
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $where): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from($table)->orderBy('uid');
        foreach ($where as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private static function stringOf(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        self::assertIsString($value);

        return $value;
    }
}
