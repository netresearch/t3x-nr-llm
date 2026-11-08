<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\DropsTheElementFileFieldHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\DropsThePageImageFieldsHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\DropsTheReferenceDeleteCommandHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\ThrowsAfterANewFileReferenceHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A chat run started in a draft workspace replaces and removes file
 * references as drafts in that workspace: `set_page_social_image` and
 * `replace_file_reference` give the parent its version, add the new
 * reference new in the workspace and mark the replaced one for deletion
 * there; live stays as it is (ADR-272).
 */
#[CoversClass(SetPageSocialImageTool::class)]
#[CoversClass(ReplaceFileReferenceTool::class)]
final class ReplacesReferencesInTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const PAGE = 1;

    /** A page the editors may edit the properties of, not the content. */
    private const PAGE_PROPERTIES_ONLY = 3;

    private const ELEMENT = 10;

    private const FILE = 1;

    private const OTHER_FILE = 2;

    /** The live og_image of page 1. */
    private const PAGE_IMAGE = 60;

    /** The live og_image of page 3. */
    private const EDITOR_PAGE_IMAGE = 61;

    /** The translation of page 1. */
    private const TRANSLATED_PAGE = 4;

    /** The translation of element 10. */
    private const TRANSLATED_ELEMENT = 20;

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
        file_put_contents($this->instancePath . '/fileadmin/two.jpg', 'second');
        $this->connectionPool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid'       => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local', 'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        foreach ([[self::FILE, 'one.jpg'], [self::OTHER_FILE, 'two.jpg']] as [$uid, $name]) {
            $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
                'uid'       => $uid, 'storage' => 1, 'identifier' => '/' . $name, 'name' => $name, 'extension' => 'jpg',
                'mime_type' => 'image/jpeg', 'size' => 5, 'sha1' => str_repeat((string) $uid, 40),
            ]);
        }

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'og_image' => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        // The editors hold PAGE_EDIT here and not CONTENT_EDIT: what the live
        // tool needs for a page's reference, as long as the page row travels
        // in the same DataHandler run.
        $pages->insert('pages', [
            'uid'           => self::PAGE_PROPERTIES_ONLY, 'pid' => self::PAGE, 'title' => 'Properties page', 'slug' => '/properties',
            'doktype'       => 1, 'og_image' => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 9, 'perms_group' => Permission::PAGE_SHOW | Permission::PAGE_EDIT, 'perms_everybody' => 0,
        ]);

        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root mount', 'identifier' => '1:/', 'read_only' => 0,
        ]);
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'                => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions'   => 'readFolder,readFile', 'tables_modify' => 'pages,tt_content,sys_file_reference',
            'non_exclude_fields' => 'pages:og_image,pages:twitter_image',
            'db_mountpoints'     => '1', 'workspace_perms' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::PAGE, 'CType' => 'textmedia', 'header' => 'Live element', 'assets' => 2, 'sorting' => 1,
        ]);

        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ([
            [40, 'tt_content', self::ELEMENT, 'assets', 1],
            [41, 'tt_content', self::ELEMENT, 'assets', 2],
            [self::PAGE_IMAGE, 'pages', self::PAGE, 'og_image', 1],
            [self::EDITOR_PAGE_IMAGE, 'pages', self::PAGE_PROPERTIES_ONLY, 'og_image', 1],
        ] as [$uid, $table, $parent, $field, $sorting]) {
            $references->insert('sys_file_reference', [
                'uid'         => $uid, 'pid' => $table === 'pages' ? $parent : self::PAGE, 'uid_local' => self::FILE, 'tablenames' => $table,
                'uid_foreign' => $parent, 'fieldname' => $field, 'sorting_foreign' => $sorting,
            ]);
        }

        // A reference another workspace added to the element.
        $references->insert('sys_file_reference', [
            'uid'         => 48, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
            'uid_foreign' => self::ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => 9,
            't3ver_wsid'  => self::OTHER_DRAFT, 't3ver_state' => 1,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        // The core StoragePermissionsAspect attaches mounts only inside a
        // backend request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ set_page_social_image

    /**
     * The page gets its version, the new reference is new in the workspace,
     * and the live reference it replaces gets a delete placeholder there —
     * addressed by its LIVE uid: named by the uid of its version, core would
     * discard that version instead and the live image would show again in the
     * workspace. A second replace discards the draft reference the first one
     * made, which never was live.
     */
    #[Test]
    public function aSocialImageReplacedInADraftWorkspaceIsADraftAndLiveKeepsItsImage(): void
    {
        $tool    = $this->tool('set_page_social_image');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true];
        $live    = $this->liveRows();

        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
        self::assertNull($tool->refusalBeforeApproval($call, $context));
        $card = $this->card($tool, $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('one.jpg', $card, 'the card names the image the workspace shows now');

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $first = $this->writtenUid($result);
        $row   = $this->row('sys_file_reference', $first);
        self::assertSame([self::DRAFT, 1, self::PAGE, self::OTHER_FILE], [(int) $row['t3ver_wsid'], (int) $row['t3ver_state'], (int) $row['uid_foreign'], (int) $row['uid_local']]);
        $pageVersion = $this->versionOf('pages', self::PAGE);
        self::assertNotNull($pageVersion);
        self::assertSame(1, (int) $pageVersion['og_image']);
        $placeholder = $this->versionOf('sys_file_reference', self::PAGE_IMAGE);
        self::assertNotNull($placeholder, 'the live image is marked for deletion in the workspace');
        self::assertSame(2, (int) $placeholder['t3ver_state']);
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'));
        self::assertSame($live, $this->liveRows(), 'a live row changed');

        // Again, back to the first file: the draft reference is discarded.
        $again = $tool->execute(['file' => self::FILE] + $call, $context);
        self::assertFalse($again->isError, $again->content);
        self::assertFalse($this->rowExists('sys_file_reference', $first), 'the draft reference of the first call is discarded');
        self::assertSame([self::FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'));
        self::assertSame(1, $this->versionCount('pages', self::PAGE));
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', self::PAGE_IMAGE)['t3ver_state'] ?? 0), 'the live image stays marked');
        self::assertSame($live, $this->liveRows(), 'a live row changed');
    }

    #[Test]
    public function aSocialImageSetOnAnEmptyFieldInADraftWorkspaceLeavesTheOtherFieldAlone(): void
    {
        $tool    = $this->tool('set_page_social_image');
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $result = $tool->execute(['page' => self::PAGE, 'field' => 'twitter_image', 'file' => self::OTHER_FILE], $context);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE, 'twitter_image'));
        self::assertSame([self::FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'));
        self::assertSame(1, (int) ($this->versionOf('pages', self::PAGE)['twitter_image'] ?? 0));
        self::assertSame($live, $this->liveRows());

        // Without "replace" the draft image is the one that is there now.
        $refused = $tool->execute(['page' => self::PAGE, 'field' => 'twitter_image', 'file' => self::FILE], $context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('already has twitter_image', $refused->content);
    }

    /**
     * An editor of page properties who may not edit the page's content
     * replaces its image in the workspace: in live the page row in the same
     * run is what lets core accept the reference under PAGE_EDIT.
     */
    #[Test]
    public function anEditorWithPageEditAloneReplacesTheSocialImageInADraftWorkspace(): void
    {
        $tool    = $this->tool('set_page_social_image');
        $editor  = $this->editorInTheDraft();
        $context = ToolExecutionContext::fromBackendUser($editor);
        $call    = ['page' => self::PAGE_PROPERTIES_ONLY, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true];
        $live    = $this->liveRows();

        self::assertStringContainsString('Workspace: “Entwurf”', $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE_PROPERTIES_ONLY, 'og_image'));
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', self::EDITOR_PAGE_IMAGE)['t3ver_state'] ?? 0));
        self::assertSame($live, $this->liveRows());
    }

    // ------------------------------------------------------------------ replace_file_reference

    /**
     * The element gets its version, the new reference takes the old one's
     * place in the workspace and the old one is marked for deletion there.
     * A reference another workspace added is not touched.
     */
    #[Test]
    public function aReferenceReplacedInADraftWorkspaceIsADraftAtTheSamePosition(): void
    {
        $tool    = $this->tool('replace_file_reference');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['reference' => 40, 'action' => 'replace', 'file' => self::OTHER_FILE, 'alternative' => 'Second'];
        $live    = $this->liveRows();
        $foreign = $this->row('sys_file_reference', 48);

        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
        self::assertNull($tool->refusalBeforeApproval($call, $context));
        $card = $this->card($tool, $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('Position: file 1 of 2', $card, "another workspace's reference is not counted");

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $row = $this->row('sys_file_reference', $this->writtenUid($result));
        self::assertSame([self::DRAFT, 1, self::ELEMENT, self::OTHER_FILE, 'Second'], [(int) $row['t3ver_wsid'], (int) $row['t3ver_state'], (int) $row['uid_foreign'], (int) $row['uid_local'], $row['alternative']]);
        self::assertSame([self::OTHER_FILE, self::FILE], $this->filesShownIn('tt_content', self::ELEMENT, 'assets'));
        self::assertSame(2, (int) ($this->versionOf('tt_content', self::ELEMENT)['assets'] ?? 0));
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', 40)['t3ver_state'] ?? 0));
        self::assertSame($live, $this->liveRows(), 'a live row changed');
        self::assertSame($foreign, $this->row('sys_file_reference', 48), "another workspace's reference changed");
    }

    #[Test]
    public function aReferenceRemovedInADraftWorkspaceIsMarkedForDeletionThere(): void
    {
        $tool    = $this->tool('replace_file_reference');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['reference' => 41, 'action' => 'remove'];
        $live    = $this->liveRows();

        self::assertStringContainsString('Workspace: “Entwurf”', $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        self::assertSame([self::FILE], $this->filesShownIn('tt_content', self::ELEMENT, 'assets'));
        self::assertSame(1, (int) ($this->versionOf('tt_content', self::ELEMENT)['assets'] ?? 0));
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', 41)['t3ver_state'] ?? 0));
        self::assertSame($live, $this->liveRows(), 'a live row changed');

        // The reference is gone from the workspace: a second call finds nothing.
        $again = $tool->execute($call, $context);
        self::assertTrue($again->isError);
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The write runs in the run's workspace while the global backend user —
     * the approving editor in a backend request — works in live.
     */
    #[Test]
    public function theWriteRunsInTheRunsWorkspaceWhileTheGlobalUserWorksInLive(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $global  = $this->adminIn(0);
        self::assertSame($global, $GLOBALS['BE_USER']);
        $live = $this->liveRows();

        $replaced = $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'replace', 'file' => self::OTHER_FILE], $context);
        $social   = $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true], $context);

        self::assertFalse($replaced->isError, $replaced->content);
        self::assertFalse($social->isError, $social->content);
        self::assertSame([self::OTHER_FILE, self::FILE], $this->filesShownIn('tt_content', self::ELEMENT, 'assets'));
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'));
        self::assertSame($live, $this->liveRows());
        self::assertSame(0, $global->workspace, 'the global user is put back');
    }

    /**
     * A run that did not record the workspace it was started in writes in
     * live only: refused before the card, nothing written.
     */
    #[Test]
    public function aRunThatDidNotRecordItsWorkspaceIsRefusedBeforeTheCard(): void
    {
        $unpinned = ToolExecutionContext::forBackendUser(
            AiActorContext::backendUser(1, true),
            $this->adminIn(self::DRAFT),
        );
        $live = $this->liveRows();

        foreach ([
            'set_page_social_image'  => ['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true],
            'replace_file_reference' => ['reference' => 40, 'action' => 'remove'],
        ] as $name => $call) {
            $tool = $this->tool($name);
            self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
            self::assertStringContainsString('does not record which workspace', $this->refusal($tool, $call, $unpinned), $name);
            self::assertStringContainsString('does not record which workspace', $this->card($tool, $call, $unpinned), $name);
            self::assertStringContainsString('does not record which workspace', $tool->execute($call, $unpinned)->content, $name);
        }

        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The page and the element already have their draft, so the references
     * below them have versions before the call. The delete addresses each
     * replaced reference by its LIVE uid, and core turns its version into a
     * delete placeholder; named by the version's uid, core would discard the
     * version and the old file would show again in the workspace beside the
     * new one. Neither tool discards the draft it did not make.
     */
    #[Test]
    public function aReferenceThatAlreadyHasADraftIsMarkedForDeletionThroughItsLiveUid(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $this->versionInTheDraft('pages', self::PAGE, $context);
        $this->versionInTheDraft('tt_content', self::ELEMENT, $context);
        self::assertNotNull($this->versionOf('sys_file_reference', self::PAGE_IMAGE), 'positive control: the reference has a version');
        self::assertNotNull($this->versionOf('sys_file_reference', 40), 'positive control: the reference has a version');
        $live = $this->liveRows();

        $social   = $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true], $context);
        $replaced = $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'replace', 'file' => self::OTHER_FILE], $context);

        self::assertFalse($social->isError, $social->content);
        self::assertFalse($replaced->isError, $replaced->content);
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'), $this->dump());
        self::assertSame([self::OTHER_FILE, self::FILE], $this->filesShownIn('tt_content', self::ELEMENT, 'assets'), $this->dump());
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', self::PAGE_IMAGE)['t3ver_state'] ?? 0));
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', 40)['t3ver_state'] ?? 0));
        self::assertSame(1, $this->versionCount('pages', self::PAGE));
        self::assertSame(1, $this->versionCount('tt_content', self::ELEMENT));
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A write whose read-back fails — here an installation's hook drops the
     * page's side of the relation — takes back the page version the call
     * made, and with it the reference it added: the workspace is as it was.
     */
    #[Test]
    public function aFailedWriteDiscardsThePageVersionItMadeAndTheReferenceWithIt(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsThePageImageFieldsHook::class,
            fn (): ToolResult => $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'twitter_image', 'file' => self::OTHER_FILE], $context),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('The draft of the page this call made was discarded', $result->content);
        self::assertNull($this->versionOf('pages', self::PAGE), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The same failure for an editor who may edit the page's properties and
     * not its content. Core discards a reference's version under CONTENT_EDIT
     * unless a `pages` row is in the run (`DataHandler::hasPermissionToUpdate()`),
     * so the take-back has to carry the page, or the new reference and the
     * version of the page's live image stay in the workspace. Whether the
     * workspace is as it was is read from its rows, not from the page's
     * version alone.
     */
    #[Test]
    public function anEditorsFailedWriteLeavesNoReferenceInTheWorkspace(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editorInTheDraft());
        $live    = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsThePageImageFieldsHook::class,
            fn (): ToolResult => $this->tool('set_page_social_image')->execute(['page' => self::PAGE_PROPERTIES_ONLY, 'field' => 'twitter_image', 'file' => self::OTHER_FILE], $context),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('The draft of the page this call made was discarded', $result->content);
        self::assertNull($this->versionOf('pages', self::PAGE_PROPERTIES_ONLY), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A failed replace on a page whose translation follows its image: core's
     * synchronisation versioned the translation and marked its copy of the
     * image for deletion. The take-back discards those drafts of this run too.
     */
    #[Test]
    public function aFailedReplaceOnATranslatedPageDiscardsTheTranslationsDraftsToo(): void
    {
        $this->declareSiteLanguages();
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'              => self::TRANSLATED_PAGE, 'pid' => 0, 'title' => 'Wurzel', 'slug' => '/', 'doktype' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => self::PAGE,
            'perms_userid'     => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $set = $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::FILE, 'replace' => true], $this->contextIn(0));
        self::assertFalse($set->isError, $set->content);
        $live = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsThePageImageFieldsHook::class,
            fn (): ToolResult => $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('The draft of the page this call made was discarded', $result->content);
        self::assertSame(0, $this->workspaceRowCount('pages', self::DRAFT), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($this->liveRowsAfterTranslationVersioning($live), $this->liveRows());
    }

    /**
     * A hook that throws half-way through the write: the exception leaves
     * the tool, and the page version the call made is gone with everything
     * below it.
     */
    #[Test]
    public function aSocialImageWriteThatThrowsDiscardsThePageVersionItMade(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $thrown = $this->thrownBy(fn (): ToolResult => $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'twitter_image', 'file' => self::OTHER_FILE], $context));

        self::assertSame(ThrowsAfterANewFileReferenceHook::MESSAGE, $thrown);
        self::assertNull($this->versionOf('pages', self::PAGE), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A replacement whose read-back fails — the element's side of the
     * relation is dropped — takes back the element version the call made,
     * with the new reference and the versions of the element's references.
     */
    #[Test]
    public function aFailedReplacementDiscardsTheElementVersionItMade(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsTheElementFileFieldHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 41, 'action' => 'replace', 'file' => self::OTHER_FILE], $context),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('The draft of the element this call made was discarded', $result->content);
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The same for a removal whose read-back fails.
     */
    #[Test]
    public function aFailedRemovalDiscardsTheElementVersionItMade(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsTheElementFileFieldHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 41, 'action' => 'remove'], $context),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('The draft of the element this call made was discarded', $result->content);
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A replacement whose delete of the old reference does not happen: the
     * element would show both images in the workspace. The element version
     * the call made is discarded, as `set_page_social_image` does in the same
     * case.
     */
    #[Test]
    public function aReplacementWhoseOldReferenceStaysDiscardsTheElementVersionItMade(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $result = $this->withHook(
            'processCmdmapClass',
            DropsTheReferenceDeleteCommandHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 41, 'action' => 'replace', 'file' => self::OTHER_FILE], $context),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was not removed', $result->content);
        self::assertStringContainsString('The draft of the element this call made was discarded', $result->content);
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A replacement that throws half-way discards the element version it made.
     */
    #[Test]
    public function aReplacementThatThrowsDiscardsTheElementVersionItMade(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $live    = $this->liveRows();

        $thrown = $this->thrownBy(fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 41, 'action' => 'replace', 'file' => self::OTHER_FILE], $context));

        self::assertSame(ThrowsAfterANewFileReferenceHook::MESSAGE, $thrown);
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), $this->dump());
        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A draft the element's references held before the call stays when the
     * call's write fails. Here an editor's alternative text on reference 41
     * is a draft without a draft of the element; the failed removal of 40
     * versions the element, and core's discard of that version takes the
     * versions of the element's references with it
     * (`DataHandler::discardRecordRelations()`) — the editor's draft too,
     * unless the take-back keeps what was there before.
     */
    #[Test]
    public function aFailedRemovalKeepsAReferenceDraftThatWasThereBefore(): void
    {
        $this->alternativeTextDraftOn(41);
        $draft = $this->draftRows();
        $live  = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsTheElementFileFieldHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'remove'], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('the workspace left as it was', $result->content);
        self::assertSame($draft, $this->draftRows(), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A reference new in the workspace, saved there on its own before the
     * call, stays as well: core's discard of the element version reaches it
     * by its own uid, where it reaches a version of a live reference by the
     * live uid.
     */
    #[Test]
    public function aFailedRemovalKeepsAReferenceNewInTheWorkspaceBeforeTheCall(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_file_reference' => ['NEW1' => [
            'pid'         => self::PAGE, 'uid_local' => self::OTHER_FILE, 'tablenames' => 'tt_content',
            'uid_foreign' => self::ELEMENT, 'fieldname' => 'assets',
        ]]], [], $this->adminIn(self::DRAFT));
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), 'positive control: the element has no draft');
        $draft = $this->draftRows();
        self::assertCount(1, $draft, 'positive control: one reference new in the workspace' . "\n" . $this->dump());
        $live = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsTheElementFileFieldHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'remove'], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertSame($draft, $this->draftRows(), $result->content . "\n" . $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * The same draft stays when the old reference of a replacement is not
     * removed and the call takes its write back.
     */
    #[Test]
    public function aReplacementWhoseOldReferenceStaysKeepsAReferenceDraftThatWasThereBefore(): void
    {
        $this->alternativeTextDraftOn(41);
        $draft = $this->draftRows();
        $live  = $this->liveRows();

        $result = $this->withHook(
            'processCmdmapClass',
            DropsTheReferenceDeleteCommandHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'replace', 'file' => self::OTHER_FILE], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was not removed', $result->content);
        self::assertStringContainsString('the workspace left as it was', $result->content);
        self::assertSame($draft, $this->draftRows(), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * `attach_file_to_content_element` takes its write back through the same
     * code, and keeps the same draft when a hook throws half-way.
     */
    #[Test]
    public function anAttachmentThatThrowsKeepsAReferenceDraftThatWasThereBefore(): void
    {
        $this->alternativeTextDraftOn(41);
        $draft = $this->draftRows();
        $live  = $this->liveRows();

        $thrown = $this->thrownBy(fn (): ToolResult => $this->tool('attach_file_to_content_element')->execute(['content_element' => self::ELEMENT, 'file' => self::OTHER_FILE], $this->contextIn(self::DRAFT)));

        self::assertSame(ThrowsAfterANewFileReferenceHook::MESSAGE, $thrown);
        self::assertSame($draft, $this->draftRows(), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * Where an installation lets a table be edited live in a workspace, TYPO3
     * keeps no draft of it; both tools are refused before the card for each
     * table they write, and nothing is written (ADR-262).
     */
    #[Test]
    public function aTableTypo3KeepsNoDraftOfIsRefusedBeforeTheCard(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $calls   = [
            'set_page_social_image'  => [['pages', 'sys_file_reference'], ['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true]],
            'replace_file_reference' => [['tt_content', 'sys_file_reference'], ['reference' => 40, 'action' => 'remove']],
        ];
        $live = $this->liveRows();
        $tca  = $GLOBALS['TCA'];
        self::assertIsArray($tca);

        foreach ($calls as $name => [$tables, $call]) {
            $tool = $this->tool($name);
            self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
            self::assertNull($tool->refusalBeforeApproval($call, $context), 'positive control: ' . $name);

            foreach ($tables as $table) {
                $definition = $tca[$table] ?? null;
                self::assertIsArray($definition);
                $ctrl = $definition['ctrl'] ?? null;
                self::assertIsArray($ctrl);
                $ctrl['versioningWS_alwaysAllowLiveEdit'] = true;
                $definition['ctrl']                       = $ctrl;
                $GLOBALS['TCA']                           = [$table => $definition] + $tca;
                try {
                    self::assertStringContainsString('the TCA of ' . $table . ' has no versioning', $this->refusal($tool, $call, $context), $name . ' on ' . $table);
                    self::assertStringContainsString('the TCA of ' . $table . ' has no versioning', $this->card($tool, $call, $context), $name . ' on ' . $table);
                    self::assertStringContainsString('the TCA of ' . $table . ' has no versioning', $tool->execute($call, $context)->content, $name . ' on ' . $table);
                } finally {
                    $GLOBALS['TCA'] = $tca;
                }
            }
        }

        self::assertSame(0, $this->workspaceRowCount('sys_file_reference', self::DRAFT));
        self::assertSame(0, $this->workspaceRowCount('pages', self::DRAFT));
        self::assertSame(0, $this->workspaceRowCount('tt_content', self::DRAFT));
        self::assertSame($live, $this->liveRows());
    }

    // ------------------------------------------------------------------ translations

    /**
     * The page's translation follows its parent's image
     * (`allowLanguageSynchronization`): in the workspace it gets a copy of the
     * new image and its copy of the old one is marked for deletion, as core's
     * synchronisation of the translation makes them; live keeps both pages'
     * images. TYPO3 13.4 moves the translation's live copy to the
     * translation's own pid when that synchronisation versions the
     * translation — the tool's datamap names no pid of it; 14.3 leaves it
     * (measured in CI on 13.4 and locally on 14.3.10).
     */
    #[Test]
    public function theTranslationOfThePageFollowsTheNewImageInTheWorkspace(): void
    {
        $this->declareSiteLanguages();
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'              => self::TRANSLATED_PAGE, 'pid' => 0, 'title' => 'Wurzel', 'slug' => '/', 'doktype' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => self::PAGE,
            'perms_userid'     => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        // The translation's copy of the live image is made by core, in live:
        // where the copy row sits (`pid`) differs between TYPO3 13.4 and 14.3,
        // and each moves a hand-made copy to its own place when it versions the
        // translation.
        $set = $this->tool('set_page_social_image')->execute(
            ['page' => self::PAGE, 'field' => 'og_image', 'file' => self::FILE, 'replace' => true],
            $this->contextIn(0),
        );
        self::assertFalse($set->isError, $set->content);
        self::assertSame([self::FILE], $this->filesShownIn('pages', self::TRANSLATED_PAGE, 'og_image'), 'positive control: the translation has its copy in live');
        $live = $this->liveRows();

        $result = $this->tool('set_page_social_image')->execute(
            ['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($result->isError, $result->content . "\n" . $this->dump());
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::PAGE, 'og_image'), $this->dump());
        self::assertSame([self::OTHER_FILE], $this->filesShownIn('pages', self::TRANSLATED_PAGE, 'og_image'), $this->dump());
        self::assertSame(1, (int) ($this->versionOf('pages', self::TRANSLATED_PAGE)['og_image'] ?? 0), $this->dump());
        self::assertSame($this->liveRowsAfterTranslationVersioning($live), $this->liveRows(), 'a live row changed');
    }

    /**
     * Core marks the translated overlay of a removed reference for deletion
     * along with it, in the workspace as in live (`deleteL10nOverlayRecords()`);
     * the translated element's version then counts what it still shows.
     */
    #[Test]
    public function aRemovedReferenceTakesItsTranslatedOverlayAlongInTheWorkspace(): void
    {
        $this->declareSiteLanguages();
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'              => self::TRANSLATED_ELEMENT, 'pid' => self::PAGE, 'CType' => 'textmedia', 'header' => 'Übersetzt', 'assets' => 1,
            'sys_language_uid' => 1, 'l18n_parent' => self::ELEMENT, 'sorting' => 2,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'              => 42, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
            'uid_foreign'      => self::TRANSLATED_ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => 40,
        ]);
        $live = $this->liveRows();
        $tool = $this->tool('replace_file_reference');
        $call = ['reference' => 40, 'action' => 'remove'];

        self::assertStringContainsString('references in translations, removed with it: 1', $this->card($tool, $call, $this->contextIn(self::DRAFT)));
        $result = $tool->execute($call, $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content . "\n" . $this->dump());
        self::assertStringContainsString('translated reference(s) were deleted with it', $result->content);
        self::assertSame([self::FILE], $this->filesShownIn('tt_content', self::ELEMENT, 'assets'));
        self::assertSame([], $this->filesShownIn('tt_content', self::TRANSLATED_ELEMENT, 'assets'), $this->dump());
        self::assertSame(2, (int) ($this->versionOf('sys_file_reference', 42)['t3ver_state'] ?? 0), $this->dump());
        self::assertSame(0, (int) ($this->versionOf('tt_content', self::TRANSLATED_ELEMENT)['assets'] ?? -1), $this->dump());
        self::assertSame($live, $this->liveRows(), 'a live row changed');
    }

    /**
     * A failed removal on an element whose translation carries an overlay of
     * the removed reference: core marked the overlay for deletion and
     * versioned the translated element in the same run
     * (`deleteL10nOverlayRecords()`). The take-back discards those drafts
     * too, and the translated element shows its image in the workspace again.
     */
    #[Test]
    public function aFailedRemovalTakesBackTheDraftsOfTheTranslatedElementToo(): void
    {
        $this->declareSiteLanguages();
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'              => self::TRANSLATED_ELEMENT, 'pid' => self::PAGE, 'CType' => 'textmedia', 'header' => 'Übersetzt', 'assets' => 1,
            'sys_language_uid' => 1, 'l18n_parent' => self::ELEMENT, 'sorting' => 2,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'              => 42, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'tablenames' => 'tt_content',
            'uid_foreign'      => self::TRANSLATED_ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => 40,
        ]);
        $live = $this->liveRows();

        $result = $this->withHook(
            'processDatamapClass',
            DropsTheElementFileFieldHook::class,
            fn (): ToolResult => $this->tool('replace_file_reference')->execute(['reference' => 40, 'action' => 'remove'], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('the workspace left as it was', $result->content);
        self::assertSame([], $this->draftRows(), $this->dump());
        self::assertSame([self::FILE], $this->filesShownIn('tt_content', self::TRANSLATED_ELEMENT, 'assets'), $this->dump());
        self::assertSame($live, $this->liveRows());
    }

    /**
     * A failed replace on a page whose translation already had a draft of its
     * image copy, made by an editor's save of the translation's title. Core's
     * synchronisation turned that draft into a delete placeholder in the
     * failed run; the row keeps its uid. The take-back cannot turn it back
     * without discarding the editor's draft, so it leaves it and says so —
     * never that the workspace is as it was.
     */
    #[Test]
    public function aFailedReplaceNamesTheTranslationsDraftItCouldNotPutBack(): void
    {
        $this->declareSiteLanguages();
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'              => self::TRANSLATED_PAGE, 'pid' => 0, 'title' => 'Wurzel', 'slug' => '/', 'doktype' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => self::PAGE,
            'perms_userid'     => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);
        $set = $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::FILE, 'replace' => true], $this->contextIn(0));
        self::assertFalse($set->isError, $set->content);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [self::TRANSLATED_PAGE => ['title' => 'Wurzel Entwurf']]], [], $this->adminIn(self::DRAFT));
        $dataHandler->process_datamap();

        $draft = $this->draftRows();
        $copy  = array_values(array_filter($draft, static fn (array $row): bool => $row['table'] === 'sys_file_reference'));
        self::assertCount(1, $copy, 'positive control: the translation holds a draft of its image copy' . "\n" . $this->dump());
        self::assertSame(0, $copy[0]['t3ver_state']);
        self::assertSame([self::FILE], $this->filesShownIn('pages', self::TRANSLATED_PAGE, 'og_image'));

        $result = $this->withHook(
            'processDatamapClass',
            DropsThePageImageFieldsHook::class,
            fn (): ToolResult => $this->tool('set_page_social_image')->execute(['page' => self::PAGE, 'field' => 'og_image', 'file' => self::OTHER_FILE, 'replace' => true], $this->contextIn(self::DRAFT)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringNotContainsString('left as it was', $result->content);
        self::assertStringContainsString(sprintf('sys_file_reference [%d]', $copy[0]['uid']), $result->content);
        self::assertStringContainsString('Workspaces module', $result->content);
        self::assertNull($this->versionOf('pages', self::PAGE), $this->dump());
        self::assertSame(array_keys($draft), array_keys($this->draftRows()), 'a row the call made stayed, or one of before is gone' . "\n" . $this->dump());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A site with the two languages of the fixture: core's synchronisation of
     * a translation localises a reference only into a language the page's
     * site declares.
     */
    private function declareSiteLanguages(): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/workspace';
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
                title: Deutsch
                locale: de_DE.UTF-8
                base: /de/
            YAML);
        $this->getService(SiteConfiguration::class)->resolveAllExistingSites(false);
        $this->getService(CacheManager::class)->getCache('runtime')->remove('sites-root-id-to-identifier');
    }

    /**
     * The live rows as TYPO3 leaves them once its synchronisation has versioned
     * the page's translation in the workspace: 13.4 moves the translation's
     * live image copy to the translation's pid, 14.3 leaves it (ADR-272).
     *
     * @param array<string, list<array<string, mixed>>> $live
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveRowsAfterTranslationVersioning(array $live): array
    {
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            return $live;
        }

        foreach ($live['sys_file_reference'] as $index => $row) {
            if ((int) $row['uid_foreign'] === self::TRANSLATED_PAGE && (int) $row['sys_language_uid'] === 1 && (int) $row['deleted'] === 0) {
                $live['sys_file_reference'][$index]['pid'] = self::TRANSLATED_PAGE;
            }
        }

        return $live;
    }

    /**
     * Runs `$work` with `$hook` registered in the DataHandler hook list
     * `$list`, and puts the configuration back afterwards.
     *
     * @template T
     *
     * @param 'processDatamapClass'|'processCmdmapClass' $list
     * @param Closure(): T                               $work
     *
     * @return T
     */
    private function withHook(string $list, string $hook, Closure $work): mixed
    {
        $before    = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $scOptions = is_array($before['SC_OPTIONS'] ?? null) ? $before['SC_OPTIONS'] : [];
        $tcemain   = is_array($scOptions['t3lib/class.t3lib_tcemain.php'] ?? null) ? $scOptions['t3lib/class.t3lib_tcemain.php'] : [];
        $hooks     = is_array($tcemain[$list] ?? null) ? $tcemain[$list] : [];

        $hooks['nrLlmReplacesReferencesTest']       = $hook;
        $tcemain[$list]                             = $hooks;
        $scOptions['t3lib/class.t3lib_tcemain.php'] = $tcemain;
        $GLOBALS['TYPO3_CONF_VARS']                 = ['SC_OPTIONS' => $scOptions] + $before;

        try {
            return $work();
        } finally {
            $GLOBALS['TYPO3_CONF_VARS'] = $before;
        }
    }

    /**
     * The message of what `$work` throws while a hook fails after the new
     * reference is inserted, or '' where nothing is thrown.
     *
     * @param Closure(): ToolResult $work
     */
    private function thrownBy(Closure $work): string
    {
        try {
            $this->withHook('processDatamapClass', ThrowsAfterANewFileReferenceHook::class, $work);
        } catch (Throwable $throwable) {
            return $throwable->getMessage();
        }

        return '';
    }

    /**
     * A draft of the record in the draft workspace, made as an editor's own
     * save there would make it, before the tool runs.
     */
    private function versionInTheDraft(string $table, int $uid, ToolExecutionContext $context): void
    {
        $user = $context->actingBackendUser();
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['version' => ['action' => 'new', 'label' => 'editor']]]], $user);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        self::assertNotNull($this->versionOf($table, $uid), 'positive control: the draft exists');
    }

    /**
     * An alternative text on a reference of the element, saved as a draft in
     * the draft workspace by the tool an editor's chat uses: a draft of the
     * reference without a draft of the element.
     */
    private function alternativeTextDraftOn(int $reference): void
    {
        $alt = $this->tool('set_file_reference_alternative_text')->execute(['reference' => $reference, 'alternative' => 'Draft alt'], $this->contextIn(self::DRAFT));
        self::assertFalse($alt->isError, $alt->content);
        self::assertSame('Draft alt', $this->versionOf('sys_file_reference', $reference)['alternative'] ?? null, 'positive control: the reference has its draft');
        self::assertNull($this->versionOf('tt_content', self::ELEMENT), 'positive control: the element has none');
    }

    /**
     * Every row of the draft workspace in the tables the tools write, by
     * table and uid, with what a take-back has to leave as it found it.
     *
     * @return array<string, array<string, int|string|null>>
     */
    private function draftRows(): array
    {
        $rows = [];
        foreach (['sys_file_reference' => 'uid,uid_local,uid_foreign,tablenames,fieldname,sys_language_uid,l10n_parent,alternative,deleted,t3ver_oid,t3ver_state', 'pages' => 'uid,sys_language_uid,l10n_parent,title,og_image,twitter_image,deleted,t3ver_oid,t3ver_state', 'tt_content' => 'uid,sys_language_uid,l18n_parent,header,assets,deleted,t3ver_oid,t3ver_state'] as $table => $columns) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select(...explode(',', $columns))->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', self::DRAFT))->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
                $typed = ['table' => $table];
                foreach ($row as $column => $value) {
                    $typed[(string) $column] = is_numeric($value) && in_array($column, ['uid', 'uid_local', 'uid_foreign', 'sys_language_uid', 'l10n_parent', 'l18n_parent', 'og_image', 'twitter_image', 'assets', 'deleted', 't3ver_oid', 't3ver_state'], true) ? (int) $value : (is_scalar($value) ? (string) $value : null);
                }

                $rows[$table . ':' . $typed['uid']] = $typed;
            }
        }

        return $rows;
    }

    /**
     * Every reference, page and element row, for the message of a failed
     * assertion.
     */
    private function dump(): string
    {
        $out = '';
        foreach (['sys_file_reference' => 'uid,pid,uid_local,uid_foreign,fieldname,sys_language_uid,l10n_parent,sorting_foreign,deleted,t3ver_wsid,t3ver_oid,t3ver_state', 'pages' => 'uid,pid,sys_language_uid,l10n_parent,og_image,deleted,t3ver_wsid,t3ver_oid,t3ver_state', 'tt_content' => 'uid,sys_language_uid,l18n_parent,assets,deleted,t3ver_wsid,t3ver_oid,t3ver_state'] as $table => $columns) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select(...explode(',', $columns))->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
                $out .= $table . ' ' . json_encode($row) . "\n";
            }
        }

        return $out;
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
     * The tool's refusal before approval, or '' where it has none.
     *
     * @param array<string, mixed> $call
     */
    private function refusal(ToolInterface $tool, array $call, ToolExecutionContext $context): string
    {
        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);

        return $tool->refusalBeforeApproval($call, $context)->content ?? '';
    }

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

    private function editorInTheDraft(): BackendUserAuthentication
    {
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');

        return $editor;
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

    private function rowExists(string $table, int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)->where($queryBuilder->expr()->eq('uid', $uid))->executeQuery()->fetchOne() > 0;
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

    private function workspaceRowCount(string $table, int $workspace): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', $workspace))->executeQuery()->fetchOne();
    }

    /**
     * The files a parent's field shows in the draft workspace, in their order
     * there, built from the stored rows independently of the tools: the live
     * references overlaid with their version in the draft (a delete
     * placeholder hides one) and the references new in the draft.
     *
     * @return list<int>
     */
    private function filesShownIn(string $table, int $parent, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder->select('*')->from('sys_file_reference')->where(
            $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
            $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
            $queryBuilder->expr()->eq('deleted', 0),
        )->executeQuery()->fetchAllAssociative();

        $shown = [];
        foreach ($rows as $row) {
            $workspace = (int) $row['t3ver_wsid'];
            if ($workspace === 0) {
                $version = null;
                foreach ($rows as $candidate) {
                    if ((int) $candidate['t3ver_oid'] === (int) $row['uid'] && (int) $candidate['t3ver_wsid'] === self::DRAFT) {
                        $version = $candidate;
                    }
                }

                if ($version !== null && (int) $version['t3ver_state'] === 2) {
                    continue;
                }

                $seen = $version ?? $row;
            } elseif ($workspace === self::DRAFT && (int) $row['t3ver_state'] === 1) {
                $seen = $row;
            } else {
                continue;
            }

            if ((int) $seen['uid_foreign'] !== $parent) {
                continue;
            }

            $shown[] = [(int) $seen['sorting_foreign'], (int) $row['uid'], (int) $seen['uid_local']];
        }

        sort($shown);

        return array_map(static fn (array $entry): int => $entry[2], $shown);
    }

    /**
     * Every live row of the tables the tools write, whole — but the two
     * columns of a live file reference core rewrites when it versions the
     * reference's parent, `tstamp` and `sorting_foreign` (ADR-264).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveRows(): array
    {
        $rows = [];
        foreach (['pages', 'tt_content', 'sys_file_reference'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();

            /** @var list<array<string, mixed>> $all */
            $all = $queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', 0))->orderBy('uid')->executeQuery()->fetchAllAssociative();
            if ($table === 'sys_file_reference') {
                $all = array_map(static fn (array $row): array => array_diff_key($row, ['tstamp' => true, 'sorting_foreign' => true]), $all);
            }

            $rows[$table] = $all;
        }

        return $rows;
    }
}
