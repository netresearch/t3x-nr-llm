<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * The file metadata writers write a version of `sys_file_metadata` in the
 * draft workspace the run was started in (ADR-262), as an editor's save in
 * the file list does: core declares the table `versioningWS`.
 *
 * Measured here, as a non-admin editor with a file mount and a page mount, and
 * a member of the workspace — an administrator passes every check by default:
 *
 * - the DataHandler stores a version (`t3ver_wsid`, `t3ver_oid`) and leaves
 *   the live row;
 * - the Workspaces module's own query
 *   ({@see WorkspaceService::selectVersionsInWorkspace()}) lists that version
 *   for the editor, so it can be published or discarded there;
 * - the frontend preview of the workspace shows the version's value, while
 *   core's metadata lookup outside the frontend shows the live one.
 *
 * The storage ROW is inserted directly so the ResourceStorage OBJECT is first
 * built while the editor is logged in inside a backend request (the recipe of
 * {@see SetFileAlternativeTextToolFileMountTest}).
 */
#[CoversClass(SetFileAlternativeTextTool::class)]
#[CoversClass(UpdateFalAssetMetaTool::class)]
final class WritesFileMetadataIntoTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath"><value index="vDEF">fileadmin/</value></field>
                <field index="pathType"><value index="vDEF">relative</value></field>
                <field index="caseSensitive"><value index="vDEF">1</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>';

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const FILE = 10;

    private const METADATA = 100;

    /** A version of the metadata in the OTHER workspace, with the lower uid. */
    private const OTHER_DRAFTS_METADATA = 99;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');

        $storage = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $storage->insert('sys_file_storage', [
            'uid'           => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $storage->insert('sys_filemounts', ['uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/', 'read_only' => 0]);
        $storage->insert('be_groups', [
            'uid'              => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1', 'db_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups. The workspace the
        // editor works in is the draft one, of which they are a member.
        $storage->update('be_users', ['usergroup' => '9', 'options' => 3, 'workspace_id' => self::DRAFT], ['uid' => 2]);

        $workspaces = $this->connectionPool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => 'be_users_2']);

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 9, 'perms_group' => Permission::ALL, 'perms_everybody' => 0,
        ]);

        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'             => self::FILE, 'pid' => 0, 'storage' => 1, 'identifier' => '/docs/manual.txt',
            'identifier_hash' => 'fixture-10', 'folder_hash' => 'fixture-folder-10', 'name' => 'manual.txt',
            'extension'       => 'txt', 'mime_type' => 'text/plain', 'size' => 10, 'type' => 1, 'missing' => 0,
        ]);
        $metadata = $this->connectionPool->getConnectionForTable('sys_file_metadata');
        $metadata->insert('sys_file_metadata', [
            'uid'   => self::METADATA, 'pid' => 0, 'file' => self::FILE, 'sys_language_uid' => 0,
            'title' => 'Live title', 'description' => 'Live description', 'alternative' => 'Live alt',
        ]);
        $metadata->insert('sys_file_metadata', [
            'uid'        => self::OTHER_DRAFTS_METADATA, 'pid' => 0, 'file' => self::FILE, 'sys_language_uid' => 0,
            'title'      => 'Other title', 'description' => 'Other description', 'alternative' => 'Other alt',
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => self::METADATA,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function theAlternativeTextIsStoredAsAVersionTheWorkspacesModuleLists(): void
    {
        $editor  = $this->editorIn(self::DRAFT);
        $tool    = $this->previewing('set_file_alternative_text');
        $context = ToolExecutionContext::fromBackendUser($editor);
        $call    = ['uid' => self::FILE, 'alternative' => 'Draft alt'];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('“Live alt”', $card);
        self::assertStringNotContainsString('Other alt', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame('Live alt', $this->metadata(self::METADATA)['alternative'] ?? null, 'live changed');
        self::assertSame('Other alt', $this->metadata(self::OTHER_DRAFTS_METADATA)['alternative'] ?? null, "another workspace's draft changed");
        $version = $this->versionIn(self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Draft alt', $version['alternative']);
        self::assertSame(self::FILE, (int) $version['file']);

        // The Workspaces module lists it for the editor: on the page tree they
        // work in, and over all their mounts.
        $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
        foreach ([[1, 99], [-1, 0]] as [$page, $depth]) {
            $listed = $workspaceService->selectVersionsInWorkspace(self::DRAFT, -99, $page, $depth);
            self::assertSame(
                [(int) $version['uid']],
                array_map(static fn (array $row): int => (int) $row['uid'], $listed['sys_file_metadata'] ?? []),
                sprintf('the Workspaces module does not list the version (page %d)', $page),
            );
        }

        // A second change goes to the same version.
        $again = $tool->execute(['uid' => self::FILE, 'alternative' => 'Draft alt 2'], $context);
        self::assertFalse($again->isError, $again->content);
        self::assertSame('Draft alt 2', $this->versionIn(self::DRAFT)['alternative'] ?? null);
    }

    #[Test]
    public function theFrontendPreviewOfTheWorkspaceShowsTheVersionAndTheBackendLookupShowsLive(): void
    {
        $editor = $this->editorIn(self::DRAFT);
        self::assertFalse($this->previewing('set_file_alternative_text')->execute(
            ['uid' => self::FILE, 'alternative' => 'Draft alt'],
            ToolExecutionContext::fromBackendUser($editor),
        )->isError);

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('workspace', new WorkspaceAspect(self::DRAFT));

        // Core's lookup outside the frontend: the live row.
        self::assertSame('Live alt', GeneralUtility::makeInstance(MetaDataRepository::class)->findByFileUid(self::FILE)['alternative'] ?? null);

        // A frontend request previewing the workspace: the version.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        self::assertSame('Draft alt', GeneralUtility::makeInstance(MetaDataRepository::class)->findByFileUid(self::FILE)['alternative'] ?? null);
    }

    #[Test]
    public function titleAndDescriptionAreStoredAsAVersion(): void
    {
        $editor  = $this->editorIn(self::DRAFT);
        $tool    = $this->previewing('update_fal_asset_meta');
        $context = ToolExecutionContext::fromBackendUser($editor);
        $call    = ['uid' => self::FILE, 'title' => 'Draft title', 'description' => 'Draft description'];

        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('“Live title”', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame('Live title', $this->metadata(self::METADATA)['title'] ?? null, 'live changed');
        $version = $this->versionIn(self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Draft title', $version['title']);
        self::assertSame('Draft description', $version['description']);

        // The card now shows the workspace's values as the current ones.
        self::assertStringContainsString('“Draft title”', implode("\n", $tool->previewCall(['uid' => self::FILE, 'title' => 'x'], $context)));
    }

    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceWritesNoMetadataInADraftWorkspace(): void
    {
        $editor  = $this->editorIn(self::DRAFT);
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(2), $editor);

        foreach ([
            'set_file_alternative_text' => ['uid' => self::FILE, 'alternative' => 'x'],
            'update_fal_asset_meta'     => ['uid' => self::FILE, 'title' => 'x'],
        ] as $name => $call) {
            $tool = $this->previewing($name);
            self::assertStringContainsString('does not record which workspace', implode("\n", $tool->previewCall($call, $context)), $name);
            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('does not record which workspace', $result->content, $name);
        }

        self::assertNull($this->versionIn(self::DRAFT));
        self::assertSame('Live alt', $this->metadata(self::METADATA)['alternative'] ?? null);
    }

    /**
     * Where an installation took the versioning off the metadata table, the
     * DataHandler would write the live row in a draft workspace: both writers
     * refuse (ADR-262).
     */
    #[Test]
    public function metadataTheInstallationDoesNotVersionIsRefusedInADraftWorkspace(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editorIn(self::DRAFT));
        $this->setVersioning('sys_file_metadata', false);

        try {
            foreach ([
                'set_file_alternative_text' => ['uid' => self::FILE, 'alternative' => 'x'],
                'update_fal_asset_meta'     => ['uid' => self::FILE, 'title' => 'x'],
            ] as $name => $call) {
                $result = $this->previewing($name)->execute($call, $context);
                self::assertTrue($result->isError, $name);
                self::assertStringContainsString('no draft of sys_file_metadata', $result->content, $name);
            }
        } finally {
            $this->setVersioning('sys_file_metadata', true);
        }

        self::assertNull($this->versionIn(self::DRAFT));
        self::assertSame('Live alt', $this->metadata(self::METADATA)['alternative'] ?? null);
        self::assertSame('Live title', $this->metadata(self::METADATA)['title'] ?? null);
    }

    #[Test]
    public function inLiveTheMetadataIsWrittenLive(): void
    {
        $editor = $this->editorIn(0, live: true);

        $result = $this->previewing('set_file_alternative_text')->execute(
            ['uid' => self::FILE, 'alternative' => 'Live change'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringNotContainsString('draft', $result->content);
        self::assertSame('Live change', $this->metadata(self::METADATA)['alternative'] ?? null);
        self::assertNull($this->versionIn(self::DRAFT));
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

    private function editorIn(int $workspace, bool $live = false): BackendUserAuthentication
    {
        if ($live) {
            // Live access for the editor, so the live control can run.
            $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 1, 'workspace_id' => 0], ['uid' => 2]);
        }

        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertSame($workspace, $editor->workspace, 'positive control: the editor works in the workspace');
        $editor->groupData['tables_modify']      = 'sys_file_metadata';
        $editor->groupData['tables_select']      = 'sys_file_metadata,pages';
        $editor->groupData['non_exclude_fields'] = 'sys_file_metadata:title,sys_file_metadata:description,sys_file_metadata:alternative';
        // The core StoragePermissionsAspect only attaches mounts and
        // permissions when the storage object is created in a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $editor;
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

        return $tool;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metadata(int $uid): ?array
    {
        return $this->row('uid', $uid);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionIn(int $workspace): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('sys_file_metadata')->where(
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(self::METADATA, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
        )->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $column, int $value): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('sys_file_metadata')
            ->where($queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
