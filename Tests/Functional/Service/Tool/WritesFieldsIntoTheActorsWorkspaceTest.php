<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\EventListener\MarkAiWrittenRecord;
use Netresearch\NrLlm\Service\Tool\Builtin\PublishRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The writers that change fields of an existing record write a version in the
 * draft workspace the run was started in; live stays as it is (ADR-260,
 * ADR-262): page metadata, the alternative text of a file reference and the
 * hidden flag. The file metadata writers have their own class, as they need
 * a storage and file mounts.
 */
#[CoversClass(UpdatePageMetadataTool::class)]
#[CoversClass(SetFileReferenceAlternativeTextTool::class)]
#[CoversClass(PublishRecordTool::class)]
final class WritesFieldsIntoTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const PAGE = 1;

    private const HIDDEN_ELEMENT = 10;

    /** Hidden in live, already shown in the draft workspace. */
    private const SHOWN_IN_THE_DRAFT = 11;

    private const REFERENCE = 20;

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
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1,
            'description'   => 'Live description',
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => self::HIDDEN_ELEMENT, 'pid' => self::PAGE, 'CType' => 'textmedia', 'header' => 'Hidden live', 'hidden' => 1, 'image' => 1]);
        $content->insert('tt_content', ['uid' => self::SHOWN_IN_THE_DRAFT, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Shown in the draft', 'hidden' => 1]);
        $content->insert('tt_content', [
            'uid'        => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Shown in the draft', 'hidden' => 0,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::SHOWN_IN_THE_DRAFT,
        ]);

        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'  => 1, 'pid' => 0, 'storage' => 1, 'type' => 2, 'mime_type' => 'image/png',
            'name' => 'logo.png', 'identifier' => '/logo.png', 'size' => 2048,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'        => self::REFERENCE, 'pid' => self::PAGE, 'uid_local' => 1, 'uid_foreign' => self::HIDDEN_ELEMENT,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => 'Live alt',
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function pageMetadataIsStoredAsAVersionAndLiveStaysAsItIs(): void
    {
        $tool    = $this->previewing('update_page_metadata');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['uid' => self::PAGE, 'description' => 'Draft description'];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card, 'the card names the workspace written to');
        self::assertStringContainsString('“Live description”', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'), 'live changed');
        $version = $this->versionOf('pages', self::PAGE, self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Draft description', $version['description']);
        self::assertSame(0, (int) $version['t3ver_state']);

        // The card now shows the workspace's value as the current one.
        self::assertStringContainsString('“Draft description”', implode("\n", $tool->previewCall(['uid' => self::PAGE, 'description' => 'Again'], $context)));

        // The provenance mark goes on the version, not on the live page.
        $this->markWritten($result, self::DRAFT);
        self::assertSame(1, (int) ($this->versionOf('pages', self::PAGE, self::DRAFT)['tx_nrllm_ai_written'] ?? 0));
        self::assertSame(0, (int) $this->column('pages', self::PAGE, 'tx_nrllm_ai_written'));
    }

    #[Test]
    public function anotherWorkspacesPageDraftIsNeitherShownNorChanged(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'        => 40, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'description' => 'Other draft',
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => self::PAGE,
        ]);
        $tool    = $this->previewing('update_page_metadata');
        $context = $this->contextIn(self::DRAFT);

        $card = implode("\n", $tool->previewCall(['uid' => self::PAGE, 'description' => 'x'], $context));
        self::assertStringContainsString('“Live description”', $card);
        self::assertStringNotContainsString('Other draft', $card);

        $refused = $tool->execute(['uid' => 40, 'description' => 'x'], $context);
        self::assertTrue($refused->isError, 'a version row was written by its own uid');
        self::assertSame('Other draft', $this->column('pages', 40, 'description'));
    }

    #[Test]
    public function aPageMetadataRunThatDoesNotKnowItsWorkspaceWritesNothingInADraftWorkspace(): void
    {
        $tool    = $this->previewing('update_page_metadata');
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        $call    = ['uid' => self::PAGE, 'description' => 'x'];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('does not record which workspace', $card);
        self::assertStringNotContainsString('Workspace:', $card);

        $result = $tool->execute($call, $context);
        self::assertTrue($result->isError);
        self::assertStringContainsString('does not record which workspace', $result->content);
        self::assertNull($this->versionOf('pages', self::PAGE, self::DRAFT));
        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'));
    }

    #[Test]
    public function inLivePageMetadataIsWrittenLiveAndTheCardNamesNoWorkspace(): void
    {
        $tool    = $this->previewing('update_page_metadata');
        $context = $this->contextIn(0);
        $call    = ['uid' => self::PAGE, 'description' => 'Live change'];

        self::assertStringNotContainsString('Workspace', implode("\n", $tool->previewCall($call, $context)));
        $result = $tool->execute($call, $context);
        self::assertFalse($result->isError, $result->content);
        self::assertStringNotContainsString('draft', $result->content);
        self::assertSame('Live change', $this->column('pages', self::PAGE, 'description'));
        self::assertSame(0, $this->versionCount('pages', self::PAGE));
    }

    #[Test]
    public function theAlternativeTextOfAReferenceIsStoredAsAVersion(): void
    {
        $tool    = $this->previewing('set_file_reference_alternative_text');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['reference' => self::REFERENCE, 'alternative' => 'Draft alt'];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('“Live alt”', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame('Live alt', $this->column('sys_file_reference', self::REFERENCE, 'alternative'), 'live changed');
        $version = $this->versionOf('sys_file_reference', self::REFERENCE, self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Draft alt', $version['alternative']);
        self::assertSame(self::HIDDEN_ELEMENT, (int) $version['uid_foreign']);

        // A second change goes to the same version.
        $again = $tool->execute(['reference' => self::REFERENCE, 'alternative' => 'Draft alt 2'], $context);
        self::assertFalse($again->isError, $again->content);
        self::assertSame('Draft alt 2', $this->versionOf('sys_file_reference', self::REFERENCE, self::DRAFT)['alternative'] ?? null);
        self::assertSame(1, $this->versionCount('sys_file_reference', self::REFERENCE));
    }

    #[Test]
    public function aReferenceRunThatDoesNotKnowItsWorkspaceWritesNothingInADraftWorkspace(): void
    {
        $tool    = $this->previewing('set_file_reference_alternative_text');
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        $call    = ['reference' => self::REFERENCE, 'alternative' => 'x'];

        self::assertStringContainsString('does not record which workspace', implode("\n", $tool->previewCall($call, $context)));
        self::assertTrue($tool->execute($call, $context)->isError);
        self::assertNull($this->versionOf('sys_file_reference', self::REFERENCE, self::DRAFT));
    }

    #[Test]
    public function clearingTheHiddenFlagInADraftWorkspaceIsADraftAndLiveStaysHidden(): void
    {
        $tool    = $this->previewing('publish_record');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['table' => 'tt_content', 'uid' => self::HIDDEN_ELEMENT];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame(1, (int) $this->column('tt_content', self::HIDDEN_ELEMENT, 'hidden'), 'live was published');
        $version = $this->versionOf('tt_content', self::HIDDEN_ELEMENT, self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame(0, (int) $version['hidden']);
    }

    #[Test]
    public function aRecordTheDraftWorkspaceAlreadyShowsIsNotWrittenAgain(): void
    {
        $result = $this->previewing('publish_record')->execute(['table' => 'tt_content', 'uid' => self::SHOWN_IN_THE_DRAFT], $this->contextIn(self::DRAFT));

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('is not hidden in the workspace "Entwurf" (live may still hide it', $result->content);
        self::assertSame(1, $this->versionCount('tt_content', self::SHOWN_IN_THE_DRAFT));

        // Live, the same record is still hidden and is published there.
        $live = $this->previewing('publish_record')->execute(['table' => 'tt_content', 'uid' => self::SHOWN_IN_THE_DRAFT], $this->contextIn(0));
        self::assertFalse($live->isError, $live->content);
        self::assertSame(0, (int) $this->column('tt_content', self::SHOWN_IN_THE_DRAFT, 'hidden'));
    }

    #[Test]
    public function aPublishRunThatDoesNotKnowItsWorkspaceWritesNothingInADraftWorkspace(): void
    {
        $tool    = $this->previewing('publish_record');
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        $call    = ['table' => 'pages', 'uid' => self::PAGE];

        self::assertStringContainsString('does not record which workspace', implode("\n", $tool->previewCall($call, $context)));
        self::assertTrue($tool->execute($call, $context)->isError);
        self::assertSame(0, $this->versionCount('pages', self::PAGE));
    }

    /**
     * A record new in the run's own workspace is there, and is changed in
     * place: no version of a new record.
     */
    #[Test]
    public function recordsNewInTheDraftWorkspaceAreChangedInPlace(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 42, 'pid' => self::PAGE, 'title' => 'New in the draft', 'slug' => '/new', 'doktype' => 1,
            'description'  => 'New draft description',
            'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
            't3ver_wsid'   => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 14, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'New hidden', 'hidden' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'        => 21, 'pid' => self::PAGE, 'uid_local' => 1, 'uid_foreign' => self::HIDDEN_ELEMENT,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => 'New draft alt',
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $context = $this->contextIn(self::DRAFT);

        $pageTool = $this->previewing('update_page_metadata');
        self::assertStringContainsString('“New draft description”', implode("\n", $pageTool->previewCall(['uid' => 42, 'description' => 'x'], $context)));
        $page = $pageTool->execute(['uid' => 42, 'description' => 'Changed new page'], $context);
        self::assertFalse($page->isError, $page->content);
        self::assertSame('Changed new page', $this->column('pages', 42, 'description'));
        self::assertSame(0, $this->versionCount('pages', 42));

        $publish = $this->previewing('publish_record')->execute(['table' => 'tt_content', 'uid' => 14], $context);
        self::assertFalse($publish->isError, $publish->content);
        self::assertSame(0, (int) $this->column('tt_content', 14, 'hidden'));
        self::assertSame(0, $this->versionCount('tt_content', 14));

        $referenceTool = $this->previewing('set_file_reference_alternative_text');
        self::assertStringContainsString('“New draft alt”', implode("\n", $referenceTool->previewCall(['reference' => 21, 'alternative' => 'x'], $context)));
        $reference = $referenceTool->execute(['reference' => 21, 'alternative' => 'Changed new alt'], $context);
        self::assertFalse($reference->isError, $reference->content);
        self::assertSame('Changed new alt', $this->column('sys_file_reference', 21, 'alternative'));
        self::assertSame(0, $this->versionCount('sys_file_reference', 21));

        // Live, and another workspace, do not see them.
        self::assertTrue($pageTool->execute(['uid' => 42, 'description' => 'y'], $this->contextIn(0))->isError);
        self::assertTrue($pageTool->execute(['uid' => 42, 'description' => 'y'], $this->contextIn(self::OTHER_DRAFT))->isError);
        self::assertSame('Changed new page', $this->column('pages', 42, 'description'));
    }

    /**
     * A page built in the draft workspace with hidden content: the element is
     * unhidden there. An image on an element new in the draft workspace (on a
     * live page: a page new there is read live by the reference tool's checks,
     * a documented limit) gets its text.
     */
    #[Test]
    public function contentOnAPageNewInTheDraftWorkspaceIsWrittenThere(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 43, 'pid' => self::PAGE, 'title' => 'Built in the draft', 'slug' => '/built', 'doktype' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
            't3ver_wsid'   => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 15, 'pid' => 43, 'CType' => 'textmedia', 'header' => 'New on the new page', 'hidden' => 1, 'image' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 16, 'pid' => self::PAGE, 'CType' => 'textmedia', 'header' => 'New on the live page', 'image' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'        => 22, 'pid' => self::PAGE, 'uid_local' => 1, 'uid_foreign' => 16,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => '',
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $context = $this->contextIn(self::DRAFT);

        $publish = $this->previewing('publish_record');
        self::assertStringContainsString('“Built in the draft”', implode("\n", $publish->previewCall(['table' => 'tt_content', 'uid' => 15], $context)));
        $result = $publish->execute(['table' => 'tt_content', 'uid' => 15], $context);
        self::assertFalse($result->isError, $result->content);
        self::assertSame(0, (int) $this->column('tt_content', 15, 'hidden'));
        self::assertSame(0, $this->versionCount('tt_content', 15));

        $alt = $this->previewing('set_file_reference_alternative_text')->execute(['reference' => 22, 'alternative' => 'A new image'], $context);
        self::assertFalse($alt->isError, $alt->content);
        self::assertSame('A new image', $this->column('sys_file_reference', 22, 'alternative'));
        self::assertSame(0, $this->versionCount('sys_file_reference', 22));
    }

    /**
     * A version is reached through its live record, never by its own uid —
     * also a version of the run's own workspace.
     */
    #[Test]
    public function aVersionOfTheDraftWorkspaceIsNotAddressedByItsOwnUid(): void
    {
        $tool    = $this->previewing('publish_record');
        $context = $this->contextIn(self::DRAFT);

        self::assertSame(['Record not found or not permitted.'], $tool->previewCall(['table' => 'tt_content', 'uid' => 12], $context));
        self::assertTrue($tool->execute(['table' => 'tt_content', 'uid' => 12], $context)->isError);
        self::assertSame(1, $this->versionCount('tt_content', self::SHOWN_IN_THE_DRAFT));
    }

    /**
     * Whether the default-language record still hides a translation is the
     * workspace's answer: hidden only in the draft, the card says so there.
     */
    #[Test]
    public function theDefaultLanguageRecordIsAskedInTheDraftWorkspace(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 60, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Default', 'hidden' => 0]);
        $content->insert('tt_content', [
            'uid'        => 61, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Default', 'hidden' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 60,
        ]);
        $content->insert('tt_content', [
            'uid'              => 62, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Translation', 'hidden' => 1,
            'sys_language_uid' => 1, 'l18n_parent' => 60,
        ]);
        $tool = $this->previewing('publish_record');
        $call = ['table' => 'tt_content', 'uid' => 62];

        self::assertStringContainsString('the version in the default language is still hidden', implode("\n", $tool->previewCall($call, $this->contextIn(self::DRAFT))));
        self::assertStringNotContainsString('the version in the default language is still hidden', implode("\n", $tool->previewCall($call, $this->contextIn(0))));
    }

    #[Test]
    public function aPageNewInAnotherWorkspaceIsNotThere(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 41, 'pid' => self::PAGE, 'title' => 'New in the other draft', 'slug' => '/new', 'doktype' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
            't3ver_wsid'   => self::OTHER_DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $tool    = $this->previewing('update_page_metadata');
        $context = $this->contextIn(self::DRAFT);

        self::assertSame(['Page not found or not permitted.'], $tool->previewCall(['uid' => 41, 'description' => 'x'], $context));
        self::assertTrue($tool->execute(['uid' => 41, 'description' => 'x'], $context)->isError);
        self::assertSame('New in the other draft', $this->column('pages', 41, 'title'));
    }

    /**
     * An editor, not an administrator: a member of the draft workspace with
     * the page in their mount and the grants the backend form needs.
     */
    #[Test]
    public function anEditorInTheDraftWorkspaceWritesVersionsToo(): void
    {
        // Core grants page permissions only to a user in at least one group.
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 9, 'pid' => 0, 'title' => 'Editors']);
        $this->connectionPool->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => '9', 'workspace_id' => self::DRAFT, 'db_mountpoints' => (string) self::PAGE], ['uid' => 2]);
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');
        $editor->groupData['tables_modify']      = 'pages,tt_content,sys_file_reference';
        $editor->groupData['tables_select']      = 'pages,tt_content,sys_file_reference';
        $editor->groupData['non_exclude_fields'] = 'pages:description,tt_content:hidden,tt_content:image,sys_file_reference:alternative';
        // Core copies the page into its version through a new record, which
        // asks the page type grant a live edit does not ask.
        $editor->groupData['pagetypes_select']   = '1';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:textmedia';
        $context                                 = ToolExecutionContext::fromBackendUser($editor);

        foreach ([
            'update_page_metadata'                => ['uid' => self::PAGE, 'description' => 'Editor draft'],
            'publish_record'                      => ['table' => 'tt_content', 'uid' => self::HIDDEN_ELEMENT],
            'set_file_reference_alternative_text' => ['reference' => self::REFERENCE, 'alternative' => 'Editor alt'],
        ] as $name => $call) {
            $result = $this->previewing($name)->execute($call, $context);
            self::assertFalse($result->isError, $name . ': ' . $result->content);
        }

        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'));
        self::assertSame('Editor draft', $this->versionOf('pages', self::PAGE, self::DRAFT)['description'] ?? null);
        self::assertSame(1, (int) $this->column('tt_content', self::HIDDEN_ELEMENT, 'hidden'));
        self::assertSame(0, (int) ($this->versionOf('tt_content', self::HIDDEN_ELEMENT, self::DRAFT)['hidden'] ?? 1));
        self::assertSame('Live alt', $this->column('sys_file_reference', self::REFERENCE, 'alternative'));
        self::assertSame('Editor alt', $this->versionOf('sys_file_reference', self::REFERENCE, self::DRAFT)['alternative'] ?? null);
    }

    /**
     * Where an installation took the versioning off a table, the DataHandler
     * would write the live row in a draft workspace: the writers refuse
     * (ADR-262).
     */
    #[Test]
    public function aTableTheInstallationDoesNotVersionIsRefusedInADraftWorkspace(): void
    {
        $context = $this->contextIn(self::DRAFT);

        foreach ([
            ['update_page_metadata', 'pages', ['uid' => self::PAGE, 'description' => 'x']],
            ['publish_record', 'tt_content', ['table' => 'tt_content', 'uid' => self::HIDDEN_ELEMENT]],
            ['set_file_reference_alternative_text', 'sys_file_reference', ['reference' => self::REFERENCE, 'alternative' => 'x']],
        ] as [$name, $table, $call]) {
            $this->setVersioning($table, false);
            try {
                $result = $this->previewing($name)->execute($call, $context);
            } finally {
                $this->setVersioning($table, true);
            }

            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('the TCA of ' . $table . ' has no versioning or allows editing live', $result->content, $name);
            self::assertSame(0, $this->versionCount($table, $name === 'set_file_reference_alternative_text' ? self::REFERENCE : ($table === 'pages' ? self::PAGE : self::HIDDEN_ELEMENT)), $name);
        }

        self::assertSame('Live description', $this->column('pages', self::PAGE, 'description'));
        self::assertSame(1, (int) $this->column('tt_content', self::HIDDEN_ELEMENT, 'hidden'));
        self::assertSame('Live alt', $this->column('sys_file_reference', self::REFERENCE, 'alternative'));
    }

    /**
     * Turns the versioning of a table on or off in the loaded TCA, as an
     * installation's TCA override would.
     */
    private function setVersioning(string $table, bool $versioned): void
    {
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $entry = $tca[$table] ?? null;
        self::assertIsArray($entry);
        $ctrl = $entry['ctrl'] ?? null;
        self::assertIsArray($ctrl);
        $ctrl['versioningWS'] = $versioned;
        $entry['ctrl']        = $ctrl;
        $tca[$table]          = $entry;
        $GLOBALS['TCA']       = $tca;
    }

    private function markWritten(ToolResult $result, int $workspace): void
    {
        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertNotNull($result->writeKind);
        $listener = $this->get(MarkAiWrittenRecord::class);
        self::assertInstanceOf(MarkAiWrittenRecord::class, $listener);
        $listener(new AfterAiRecordWrittenEvent('run-1', $result->writeTarget, $result->writeKind, $workspace));
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

    /**
     * @return ToolInterface&ToolPreviewInterface
     */
    private function previewing(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($name, true);

        return $tool;
    }

    /**
     * One column of a row, hidden or not: the plain `select()` of a
     * connection applies the frontend restrictions and would read a hidden
     * row as missing.
     */
    private function column(string $table, int $uid, string $column): mixed
    {
        $row = $this->row($table, ['uid' => $uid]);
        self::assertNotNull($row, sprintf('%s [%d] is gone', $table, $uid));

        return $row[$column] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionOf(string $table, int $uid, int $workspace): ?array
    {
        return $this->row($table, ['t3ver_oid' => $uid, 't3ver_wsid' => $workspace]);
    }

    /**
     * @param array<string, int> $where
     *
     * @return array<string, mixed>|null
     */
    private function row(string $table, array $where): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from($table);
        foreach ($where as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)));
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function versionCount(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from($table)
            ->where($queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }
}
