<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRecordDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatesRecordsInTheRunsWorkspaceTrait;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A chat run started in a draft workspace attaches a file and creates a
 * record of a versioned table as drafts in that workspace; live stays as it
 * is, live file references included, and a table TYPO3 does not version is
 * refused before the approval card (ADR-264).
 */
#[CoversClass(AttachFileToContentElementTool::class)]
#[CoversClass(AttachFileToRecordTool::class)]
#[CoversClass(CreateRecordDraftTool::class)]
#[CoversTrait(CreatesRecordsInTheRunsWorkspaceTrait::class)]
final class AttachesAndCreatesRecordsInTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const PAGE = 1;

    private const ELEMENT = 10;

    private const ELEMENT_WITH_DRAFT = 11;

    private const ELEMENT_NEW_IN_DRAFT = 13;

    private const ELEMENT_NEW_IN_OTHER_DRAFT = 16;

    private const RECORD = 30;

    private const RECORD_NEW_IN_DRAFT = 31;

    private const FILE = 1;

    private const OTHER_FILE = 2;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $workspaces = $this->connectionPool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other']);

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin');
        file_put_contents($this->instancePath . '/fileadmin/one.jpg', 'first');
        $this->connectionPool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid'       => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local', 'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'       => self::FILE, 'storage' => 1, 'identifier' => '/one.jpg', 'name' => 'one.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'size' => 5, 'sha1' => str_repeat('1', 40),
        ]);
        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'       => self::OTHER_FILE, 'storage' => 1, 'identifier' => '/two.jpg', 'name' => 'two.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'size' => 5, 'sha1' => str_repeat('2', 40),
        ]);

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 254,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        // Page 3 is open to the editors' group; page 1 only to its owner and
        // everybody, which one test closes.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => 3, 'pid' => self::PAGE, 'title' => 'Open page', 'slug' => '/open', 'doktype' => 254,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 9, 'perms_group' => Permission::ALL, 'perms_everybody' => 0,
        ]);
        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root mount', 'identifier' => '1:/', 'read_only' => 0,
        ]);
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'              => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile', 'tables_modify' => 'tt_content,sys_file_reference,tx_writerfixture_draftable',
            'db_mountpoints'   => '1', 'explicit_allowdeny' => 'tt_content:CType:textmedia', 'workspace_perms' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, 'Live element', 0, 0, 0, 1],
            [self::ELEMENT_WITH_DRAFT, 'Live second', 0, 0, 0, 1],
            [12, 'Second in the draft', self::DRAFT, self::ELEMENT_WITH_DRAFT, 0, 1],
            [self::ELEMENT_NEW_IN_DRAFT, 'New in the draft', self::DRAFT, 0, 1, 0],
            [self::ELEMENT_NEW_IN_OTHER_DRAFT, 'OTHER-DRAFT element', self::OTHER_DRAFT, 0, 1, 0],
            // Element 17 lives on page 1 and is moved to page 3 in the draft.
            [17, 'Moved in the draft', 0, 0, 0, 0],
            [18, 'Moved in the draft', self::DRAFT, 17, 4, 0],
        ] as [$uid, $header, $workspace, $original, $state, $assets]) {
            $content->insert('tt_content', [
                'uid'     => $uid, 'pid' => $uid === 18 ? 3 : self::PAGE, 'CType' => 'textmedia', 'header' => $header, 'assets' => $assets,
                'sorting' => $uid, 't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
            ]);
        }

        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ([[40, self::ELEMENT], [41, self::ELEMENT_WITH_DRAFT]] as [$uid, $element]) {
            $references->insert('sys_file_reference', [
                'uid'         => $uid, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
                'uid_foreign' => $element, 'fieldname' => 'assets', 'sorting_foreign' => 1,
            ]);
        }

        // A reference another workspace added to element 10.
        $references->insert('sys_file_reference', [
            'uid'         => 48, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
            'uid_foreign' => self::ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => 9,
            't3ver_wsid'  => self::OTHER_DRAFT, 't3ver_state' => 1,
        ]);

        $records = $this->connectionPool->getConnectionForTable('tx_writerfixture_draftable');
        $records->insert('tx_writerfixture_draftable', ['uid' => self::RECORD, 'pid' => self::PAGE, 'title' => 'Live record']);
        $records->insert('tx_writerfixture_draftable', ['uid' => self::RECORD_NEW_IN_DRAFT, 'pid' => self::PAGE, 'title' => 'New record', 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1]);
        // Record 33 lives on page 1 and is moved to page 3 in the draft; 35 is
        // another workspace's.
        $records->insert('tx_writerfixture_draftable', ['uid' => 33, 'pid' => self::PAGE, 'title' => 'Moved record']);
        $records->insert('tx_writerfixture_draftable', ['uid' => 34, 'pid' => 3, 'title' => 'Moved record', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 33, 't3ver_state' => 4]);
        $records->insert('tx_writerfixture_draftable', ['uid' => 35, 'pid' => self::PAGE, 'title' => 'OTHER-DRAFT record', 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1]);
        // A folder another workspace created.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'             => 5, 'pid' => self::PAGE, 'title' => 'OTHER-DRAFT folder', 'slug' => '/other', 'doktype' => 254,
            't3ver_wsid'      => self::OTHER_DRAFT, 't3ver_state' => 1, 'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_everybody' => Permission::ALL,
        ]);
        $this->connectionPool->getConnectionForTable('tx_writerfixture_gallery')->insert('tx_writerfixture_gallery', ['uid' => 50, 'pid' => self::PAGE, 'title' => 'Gallery']);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        // The core StoragePermissionsAspect attaches mounts only inside a
        // backend request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * The element gets its version first, and the datamap names the
     * element's references by their versions: the new one comes last in the
     * workspace. Core's versioning of the element stamps the live references
     * and renumbers their `sorting_foreign` (ADR-264); which file and in
     * which order stay. A reference another workspace added to the same
     * element is neither counted nor touched.
     */
    #[Test]
    public function aFileAttachedInADraftWorkspaceIsANewReferenceOnTheElementsVersion(): void
    {
        $tool    = $this->tool('attach_file_to_content_element');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets'];
        $live    = $this->liveRows();
        $foreign = $this->row('sys_file_reference', 48);

        $card = $this->card($tool, $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('currently 1, afterwards 2', $card, "another workspace's reference is counted");

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertStringContainsString('reference 2 of 2', $result->content);
        $reference = $this->row('sys_file_reference', $this->writtenUid($result));
        self::assertSame(self::DRAFT, (int) $reference['t3ver_wsid']);
        self::assertSame(1, (int) $reference['t3ver_state']);
        self::assertSame(self::ELEMENT, (int) $reference['uid_foreign'], 'core relates it to the live uid');
        $version = $this->versionOf('tt_content', self::ELEMENT);
        self::assertNotNull($version);
        self::assertSame(2, (int) $version['assets']);
        self::assertSame($live, $this->liveRows(), 'a live row changed');

        // A second file is appended in the same version.
        $again = $tool->execute($call, $context);
        self::assertFalse($again->isError, $again->content);
        self::assertStringContainsString('reference 3 of 3', $again->content);
        self::assertSame(1, $this->versionCount('tt_content', self::ELEMENT));
        self::assertSame($live, $this->liveRows());
        self::assertSame($foreign, $this->row('sys_file_reference', 48), "another workspace's reference changed");
    }

    /**
     * Two live references with a gap in their sorting: the new one comes
     * last in the workspace, and live keeps its order.
     */
    #[Test]
    public function theNewReferenceComesLastInTheWorkspaceAndLiveKeepsItsOrder(): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'         => 44, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
            'uid_foreign' => self::ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => 5,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['assets' => 2], ['uid' => self::ELEMENT]);

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets'],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('reference 3 of 3', $result->content);
        self::assertSame([40, 44], $this->referenceOrder(0), 'live order');
        $draft = $this->referenceOrder(self::DRAFT);
        self::assertCount(3, $draft);
        self::assertSame($this->writtenUid($result), $draft[2], 'the new reference is not last in the workspace');
    }

    #[Test]
    public function aFileIsAttachedToAnElementNewInTheDraftWorkspaceAndToOneWithADraft(): void
    {
        $tool    = $this->tool('attach_file_to_content_element');
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $onNew = $tool->execute(['content_element' => self::ELEMENT_NEW_IN_DRAFT, 'file' => self::FILE, 'field' => 'assets'], $context);
        self::assertFalse($onNew->isError, $onNew->content);
        self::assertSame(self::ELEMENT_NEW_IN_DRAFT, (int) $this->row('sys_file_reference', $this->writtenUid($onNew))['uid_foreign']);
        self::assertSame(1, (int) $this->row('tt_content', self::ELEMENT_NEW_IN_DRAFT)['assets']);
        self::assertSame(0, $this->versionCount('tt_content', self::ELEMENT_NEW_IN_DRAFT));

        self::assertStringContainsString('“Second in the draft”', $this->card($tool, ['content_element' => self::ELEMENT_WITH_DRAFT, 'file' => self::FILE, 'field' => 'assets'], $context));
        $onDraft = $tool->execute(['content_element' => self::ELEMENT_WITH_DRAFT, 'file' => self::FILE, 'field' => 'assets'], $context);
        self::assertFalse($onDraft->isError, $onDraft->content);
        self::assertSame(2, (int) $this->row('tt_content', 12)['assets']);
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A read-back that fails takes back the element version this call made:
     * the workspace is as it was, not left with a change nobody approved.
     */
    #[Test]
    public function aFailedAttachLeavesTheDraftWorkspaceAsItWas(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        $live                                           = $this->liveRows();

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"alternative" did not take', $result->content);
        self::assertSame(0, $this->versionCount('tt_content', self::ELEMENT), 'the version this call made is still there');
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), 'a reference is left in the workspace');
        self::assertSame($live, $this->liveRows());
    }

    /**
     * Core asks an update of the page the element's stored row is on, the
     * reference's insert of the page the draft moved it to; the tool asks
     * both, so an editor gets no card for a write core would refuse.
     */
    #[Test]
    public function anElementMovedInTheDraftNeedsTheEditorOnBothPages(): void
    {
        // The editor sees page 1, but may not edit content there.
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => self::PAGE]);
        $editor = $this->setUpBackendUser(2);
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');
        $context = ToolExecutionContext::fromBackendUser($editor);
        $tool    = $this->tool('attach_file_to_content_element');
        $call    = ['content_element' => 17, 'file' => self::FILE, 'field' => 'assets'];

        self::assertSame('Content element or file not found, or not permitted.', $tool->execute($call, $context)->content);
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));

        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_groupid' => 9, 'perms_group' => Permission::ALL], ['uid' => self::PAGE]);
        $result = $tool->execute($call, $context);
        self::assertFalse($result->isError, $result->content);
        self::assertSame(3, (int) $this->row('sys_file_reference', $this->writtenUid($result))['pid'], 'the reference sits where the draft shows the element');
    }

    /**
     * An element whose type the editor may not edit gets no card: core
     * would refuse the element's update after the reference was written, and
     * refuse the editor the discard of the version as well, for the same
     * reason. Nothing is written, in the workspace or in live.
     */
    #[Test]
    public function anElementOfATypeTheEditorMayNotEditTakesNoFile(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 20, 'pid' => 3, 'CType' => 'image', 'header' => 'An image element', 'image' => 0,
        ]);
        $editor = $this->setUpBackendUser(2);
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        $context = ToolExecutionContext::fromBackendUser($editor);
        $tool    = $this->tool('attach_file_to_content_element');
        $call    = ['content_element' => 20, 'file' => self::FILE, 'field' => 'image'];
        $live    = $this->liveRows();
        $drafts  = $this->workspaceRowCount('tt_content', self::DRAFT);

        self::assertSame(['Content element or file not found, or not permitted.'], $this->cardLines($tool, $call, $context));
        self::assertSame('Content element or file not found, or not permitted.', $tool->execute($call, $context)->content);
        self::assertSame($drafts, $this->workspaceRowCount('tt_content', self::DRAFT), 'a version was left in the workspace');
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A failed attach on an element the editor already changed in the draft
     * workspace keeps that draft: the call made no version, so it discards
     * none, and takes back only its own reference. The draft is made through
     * core, as an editor's save makes it, with its reference's version.
     */
    #[Test]
    public function aFailedAttachKeepsTheEditorsOwnDraft(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [self::ELEMENT => ['header' => 'Editor draft']]], [], $this->adminIn(self::DRAFT));
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $draft = $this->versionOf('tt_content', self::ELEMENT);
        self::assertNotNull($draft, 'positive control: the editor has a draft of the element');
        $references = $this->workspaceRowCount('sys_file_reference', self::DRAFT);

        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertSame('Editor draft', $this->row('tt_content', (int) $draft['uid'])['header'] ?? null, "the editor's own draft was discarded");
        self::assertSame($references, $this->workspaceRowCount('sys_file_reference', self::DRAFT), 'a reference is left in the workspace');
        self::assertSame(1, (int) $this->row('tt_content', (int) $draft['uid'])['assets']);
    }

    /**
     * A refused element update on an element the editor already changed in
     * the workspace takes the new reference back and puts the draft's list
     * back: the draft is as it was, and the answer names what did not take
     * and passes TYPO3's complaint on.
     */
    #[Test]
    public function aRefusedAttachOnTheEditorsDraftLeavesTheDraftAsItWas(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [self::ELEMENT => ['header' => 'Editor draft']]], [], $this->adminIn(self::DRAFT));
        $dataHandler->process_datamap();

        $draft = $this->versionOf('tt_content', self::ELEMENT);
        self::assertNotNull($draft, 'positive control: the editor has a draft of the element');
        $references = $this->workspaceRowCount('sys_file_reference', self::DRAFT);

        // The reference does not carry its text, and the hook complains: the
        // attach did not take; the answer names that and passes the complaint
        // on.
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complain           = true;
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"alternative" did not take', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringNotContainsString('did not restore', $result->content);
        self::assertSame($references, $this->workspaceRowCount('sys_file_reference', self::DRAFT), 'a reference is left in the workspace');
        self::assertSame(1, (int) $this->row('tt_content', (int) $draft['uid'])['assets']);
        self::assertSame('Editor draft', $this->row('tt_content', (int) $draft['uid'])['header'] ?? null);
    }

    /**
     * Where taking the reference back does not happen, the answer says so.
     */
    #[Test]
    public function aReferenceThatCouldNotBeTakenBackIsNamed(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [self::ELEMENT => ['header' => 'Editor draft']]], [], $this->adminIn(self::DRAFT));
        $dataHandler->process_datamap();
        self::assertNotNull($this->versionOf('tt_content', self::ELEMENT), 'positive control: the editor has a draft of the element');

        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        InterferesWithAnUpdateHook::$keepDraftsOf       = 'sys_file_reference';

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('did not restore tt_content:' . self::ELEMENT, $result->content);
    }

    /**
     * Where the installation edits `pages` live in a workspace, a record of a
     * versioned table is still created as a draft: `pages` is only read.
     */
    #[Test]
    public function aRecordIsCreatedEvenWhereThePagesAreEditedLive(): void
    {
        $original = $GLOBALS['TCA'];
        /** @var array<string, array<string, array<string, mixed>>> $tca */
        $tca                                                      = $original;
        $tca['pages']['ctrl']['versioningWS_alwaysAllowLiveEdit'] = true;
        $GLOBALS['TCA']                                           = $tca;

        try {
            $result = $this->tool('create_record_draft')->execute(
                ['table' => 'tx_writerfixture_draftable', 'pid' => self::PAGE, 'fields' => ['title' => 'Still a draft']],
                $this->contextIn(self::DRAFT),
            );
        } finally {
            $GLOBALS['TCA'] = $original;
        }

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::DRAFT, (int) $this->row('tx_writerfixture_draftable', $this->writtenUid($result))['t3ver_wsid']);
    }

    /**
     * A complaint about the version command, with the version made all the
     * same, does not stop the attach: the version is there, the reference
     * lands on it, and the complaint is passed on (ADR-198).
     */
    #[Test]
    public function aComplaintAboutTheVersionIsPassedOnBesideTheAttach(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complainOnCommand = true;
        $live                                          = $this->liveRows();

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets'],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace', $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('A test hook complains about a command and carries on', $result->content);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame(1, $this->versionCount('tt_content', self::ELEMENT));
        self::assertSame(2, (int) ($this->versionOf('tt_content', self::ELEMENT)['assets'] ?? 0));
        self::assertSame(self::DRAFT, (int) $this->row('sys_file_reference', $this->writtenUid($result))['t3ver_wsid']);
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A reference that does not point at the file asked for — a hook of the
     * installation swapped it — is no attach of that file: the call is taken
     * back like any other failed read-back.
     */
    #[Test]
    public function aReferenceToAnotherFileIsNoAttach(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$fileOnCreate = self::OTHER_FILE;
        $live                                     = $this->liveRows();

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringStartsWith('The reference was not stored for the named file', $result->content);
        self::assertSame(0, $this->versionCount('tt_content', self::ELEMENT), 'the version this call made is still there');
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The version made beside a complaint is this call's: an attach that
     * then fails its read-back takes it back, and the workspace is as it was.
     */
    #[Test]
    public function aVersionMadeBesideAComplaintIsTakenBackWhenTheAttachFails(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complainOnCommand  = true;
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        $live                                           = $this->liveRows();

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"alternative" did not take', $result->content);
        self::assertSame(0, $this->versionCount('tt_content', self::ELEMENT), 'the version this call made is still there');
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), 'a reference is left in the workspace');
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A complaint in the attach's own run about another record — a hook of
     * the installation that logs and carries on — and the attach that landed
     * is reported as written with the complaint passed on (ADR-198).
     */
    #[Test]
    public function anAttachThatLandsBesideAComplaintIsReportedAsWritten(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complainOnEveryRecord = true;
        $live                                              = $this->liveRows();

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets'],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $result->content);
        self::assertStringContainsString('A test hook complains about a record and carries on', $result->content);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame(self::DRAFT, (int) $this->row('sys_file_reference', $this->writtenUid($result))['t3ver_wsid']);
        self::assertSame(1, $this->versionCount('tt_content', self::ELEMENT));
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The same for a record created, and a file attached to a record of
     * another table: before, the created record was left in the workspace
     * under "refused" (ADR-198).
     */
    #[Test]
    public function aRecordCreatedOrAttachedBesideAComplaintIsReportedAsWritten(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complainOnEveryRecord = true;
        $drafts                                            = $this->workspaceRowCount('tx_writerfixture_draftable', self::DRAFT);

        $created = $this->tool('create_record_draft')->execute(
            ['table' => 'tx_writerfixture_draftable', 'pid' => self::PAGE, 'fields' => ['title' => 'Beside a refusal']],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($created->isError, $created->content);
        self::assertStringContainsString('A test hook complains about a record and carries on', $created->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $created->content);
        self::assertSame(WriteCompleteness::COMPLETE, $created->writeCompleteness);
        self::assertSame($drafts + 1, $this->workspaceRowCount('tx_writerfixture_draftable', self::DRAFT));
        self::assertSame('Beside a refusal', $this->row('tx_writerfixture_draftable', $this->writtenUid($created))['title'] ?? null);

        $attached = $this->tool('attach_file_to_record')->execute(
            ['table' => 'tx_writerfixture_draftable', 'record' => self::RECORD, 'file' => self::FILE, 'field' => 'media'],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($attached->isError, $attached->content);
        self::assertStringContainsString(' TYPO3 also reported: ', $attached->content);
        self::assertSame(WriteCompleteness::COMPLETE, $attached->writeCompleteness);
        self::assertSame(self::DRAFT, (int) $this->row('sys_file_reference', $this->writtenUid($attached))['t3ver_wsid']);
    }

    /**
     * Where the discard of the version this call made does not happen, the
     * answer says so: the draft is left for a human.
     */
    #[Test]
    public function aVersionThatCouldNotBeDiscardedIsNamed(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        InterferesWithAnUpdateHook::$keepDraftsOf       = 'tt_content';

        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT, 'file' => self::FILE, 'field' => 'assets', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be discarded and needs a look in the Workspaces module', $result->content);
    }

    /**
     * The same for a record of another table, through the generic attach.
     */
    #[Test]
    public function aRecordMovedInTheDraftNeedsTheEditorOnBothPages(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => self::PAGE]);
        $editor = $this->setUpBackendUser(2);
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        $context = ToolExecutionContext::fromBackendUser($editor);
        $tool    = $this->tool('attach_file_to_record');
        $call    = ['table' => 'tx_writerfixture_draftable', 'record' => 33, 'file' => self::FILE, 'field' => 'media'];

        self::assertSame('Record or file not found, or not permitted.', $tool->execute($call, $context)->content);
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));

        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_groupid' => 9, 'perms_group' => Permission::ALL], ['uid' => self::PAGE]);
        $result = $tool->execute($call, $context);
        self::assertFalse($result->isError, $result->content);
    }

    /**
     * A failed attach to a record takes back the version this call made.
     */
    #[Test]
    public function aFailedAttachToARecordLeavesTheDraftWorkspaceAsItWas(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';
        $live                                           = $this->liveRows();

        $result = $this->tool('attach_file_to_record')->execute(
            ['table' => 'tx_writerfixture_draftable', 'record' => self::RECORD, 'file' => self::FILE, 'field' => 'media', 'alternative' => 'Alt'],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"alternative" did not take', $result->content);
        self::assertStringNotContainsString('could not be discarded', $result->content);
        self::assertSame(0, $this->versionCount('tx_writerfixture_draftable', self::RECORD), 'the version this call made is still there');
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), 'a reference is left in the workspace');
        self::assertSame($live, $this->liveRows());
    }

    /**
     * Another workspace's record takes no file, and another workspace's
     * folder takes no record.
     */
    #[Test]
    public function anotherWorkspacesRecordAndFolderAreNotThere(): void
    {
        $context = $this->contextIn(self::DRAFT);

        $attach = $this->tool('attach_file_to_record')->execute(['table' => 'tx_writerfixture_draftable', 'record' => 35, 'file' => self::FILE, 'field' => 'media'], $context);
        self::assertSame('Record or file not found, or not permitted.', $attach->content);

        $create = $this->tool('create_record_draft')->execute(['table' => 'tx_writerfixture_draftable', 'pid' => 5, 'fields' => ['title' => 'x']], $context);
        self::assertTrue($create->isError);
        self::assertStringContainsString('not found or not permitted', $create->content);
        self::assertStringNotContainsString('OTHER-DRAFT', $create->content . $attach->content);
        self::assertSame(2, $this->workspaceRowCount('tx_writerfixture_draftable', self::DRAFT), 'a record was created');
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));
    }

    #[Test]
    public function anotherWorkspacesElementTakesNoFile(): void
    {
        $result = $this->tool('attach_file_to_content_element')->execute(
            ['content_element' => self::ELEMENT_NEW_IN_OTHER_DRAFT, 'file' => self::FILE, 'field' => 'assets'],
            $this->contextIn(self::DRAFT),
        );

        self::assertSame('Content element or file not found, or not permitted.', $result->content);
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));
    }

    #[Test]
    public function aFileAttachedToARecordInADraftWorkspaceIsADraftThere(): void
    {
        $tool    = $this->tool('attach_file_to_record');
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        foreach ([self::RECORD, self::RECORD_NEW_IN_DRAFT] as $record) {
            $call = ['table' => 'tx_writerfixture_draftable', 'record' => $record, 'file' => self::FILE, 'field' => 'media'];
            self::assertStringContainsString('Workspace: “Entwurf”', $this->card($tool, $call, $context));

            $result = $tool->execute($call, $context);

            self::assertFalse($result->isError, $result->content);
            $reference = $this->row('sys_file_reference', $this->writtenUid($result));
            self::assertSame(self::DRAFT, (int) $reference['t3ver_wsid']);
            self::assertSame($record, (int) $reference['uid_foreign']);
        }

        self::assertSame(1, (int) ($this->versionOf('tx_writerfixture_draftable', self::RECORD)['media'] ?? 0));
        self::assertSame(1, (int) $this->row('tx_writerfixture_draftable', self::RECORD_NEW_IN_DRAFT)['media']);
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A table TYPO3 does not version keeps no draft: the call is refused
     * before the approval card and writes nothing, for the generic creator
     * and for the generic attach.
     */
    #[Test]
    public function aTableTypo3DoesNotVersionIsRefusedBeforeTheCard(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        foreach ([
            'create_record_draft'   => ['table' => 'tx_writerfixture_variant', 'pid' => self::PAGE, 'fields' => ['title' => 'x']],
            'attach_file_to_record' => ['table' => 'tx_writerfixture_gallery', 'record' => 50, 'file' => self::FILE, 'field' => 'media'],
        ] as $name => $call) {
            $tool = $this->tool($name);
            self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
            self::assertInstanceOf(ToolResult::class, $tool->refusalBeforeApproval($call, $context), $name);
            self::assertNull($tool->refusalBeforeApproval($call, $this->contextIn(0)), $name . ' in live');
            $card = $this->card($tool, $call, $context);
            self::assertStringContainsString('Not possible in the workspace “Entwurf”: ', $card, $name);
            self::assertStringNotContainsString('saved there as a draft', $card, $name);

            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('has no versioning or allows editing live', $result->content, $name);
        }

        $creator = $this->tool('create_record_draft');
        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $creator);
        self::assertNull($creator->refusalBeforeApproval(['table' => 'tx_writerfixture_draftable', 'pid' => self::PAGE, 'fields' => ['title' => 'x']], $context));
        self::assertSame(0, $this->connectionPool->getConnectionForTable('tx_writerfixture_variant')->count('uid', 'tx_writerfixture_variant', []));
        self::assertSame($live, $this->liveRows());
    }

    #[Test]
    public function aRecordCreatedInADraftWorkspaceIsNewThere(): void
    {
        $tool    = $this->tool('create_record_draft');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tx_writerfixture_draftable', 'pid' => self::PAGE, 'fields' => ['title' => 'AI record']];
        $live    = $this->liveRows();

        self::assertStringContainsString('Workspace: “Entwurf”', $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $row = $this->row('tx_writerfixture_draftable', $this->writtenUid($result));
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertSame(1, (int) $row['t3ver_state']);
        self::assertSame(1, (int) $row['hidden']);
        self::assertSame('AI record', $row['title']);
        self::assertSame($live, $this->liveRows());

        // A run that did not record its workspace creates nothing there.
        $unpinned = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        self::assertStringContainsString('does not record which workspace', $this->card($tool, $call, $unpinned));
        self::assertStringContainsString('does not record which workspace', $tool->execute($call, $unpinned)->content);
        self::assertSame(3, $this->workspaceRowCount('tx_writerfixture_draftable', self::DRAFT), 'the unpinned run created a record');
    }

    /**
     * @param array<string, mixed> $call
     */
    private function card(ToolInterface $tool, array $call, ToolExecutionContext $context): string
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        return implode("\n", $tool->previewCall($call, $context));
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return list<string>
     */
    private function cardLines(ToolInterface $tool, array $call, ToolExecutionContext $context): array
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        return $tool->previewCall($call, $context);
    }

    private function contextIn(int $workspace): ToolExecutionContext
    {
        return ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], $workspace), $this->adminIn($workspace));
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

    private function writtenUid(ToolResult $result): int
    {
        self::assertNotNull($result->writeTarget);

        return $result->writeTarget->uid;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('uid', $uid))->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionOf(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from($table)->where(
            $queryBuilder->expr()->eq('t3ver_oid', $uid),
            $queryBuilder->expr()->eq('t3ver_wsid', self::DRAFT),
        )->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function versionCount(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)->where($queryBuilder->expr()->eq('t3ver_oid', $uid))->executeQuery()->fetchOne();
    }

    /**
     * The references of element 10's assets stored in one workspace, by their
     * order there.
     *
     * @return list<int>
     */
    private function referenceOrder(int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(intval(...), $queryBuilder->select('uid')->from('sys_file_reference')->where(
            $queryBuilder->expr()->eq('uid_foreign', self::ELEMENT),
            $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('assets')),
            $queryBuilder->expr()->eq('t3ver_wsid', $workspace),
            $queryBuilder->expr()->eq('deleted', 0),
        )->orderBy('sorting_foreign')->addOrderBy('uid')->executeQuery()->fetchFirstColumn());
    }

    private function workspaceRowCount(string $table, int $workspace): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', $workspace))->executeQuery()->fetchOne();
    }

    /**
     * Every live row of the tables the tools write, whole — but the two
     * columns of a live file reference core rewrites when it versions the
     * reference's parent, `tstamp` and `sorting_foreign`.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveRows(): array
    {
        $rows = [];
        foreach (['pages', 'tt_content', 'sys_file_reference', 'tx_writerfixture_draftable', 'tx_writerfixture_gallery'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('*')->from($table)->orderBy('uid');
            if ($table !== 'tx_writerfixture_gallery') {
                $queryBuilder->where($queryBuilder->expr()->eq('t3ver_wsid', 0));
            }

            /** @var list<array<string, mixed>> $all */
            $all = $queryBuilder->executeQuery()->fetchAllAssociative();
            if ($table === 'sys_file_reference') {
                // Core stamps and renumbers the live children when it versions
                // their parent; their order is asserted on its own.
                $all = array_map(static fn (array $row): array => array_diff_key($row, ['tstamp' => true, 'sorting_foreign' => true]), $all);
            }

            $rows[$table] = $all;
        }

        return $rows;
    }
}
