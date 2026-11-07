<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileCategoriesTool;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogue;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\Vision\CategoryVocabulary;
use Netresearch\NrLlm\Service\Tool\Vision\ImageDownscalerInterface;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The categories writer against a real database, real file mounts and the
 * real DataHandler (ADR-268): which categories may be chosen, what the write
 * keeps, and where the relation lands in a draft workspace.
 */
#[CoversClass(SetFileCategoriesTool::class)]
#[CoversClass(CategoryVocabulary::class)]
#[CoversClass(VisionActionBuilder::class)]
final class SetFileCategoriesToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;

    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

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

    private const FILE = 10;

    private const FILE_OUTSIDE_MOUNT = 11;

    private const METADATA = 100;

    private const DRAFT = 5;

    private const ROOT = 1;

    private const ANIMALS = 2;

    private const DOGS = 3;

    private const LANDSCAPE = 4;

    private const HIDDEN = 5;

    private const OTHER_TREE = 6;

    private const DELETED = 7;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/docs/dog.jpg', 'a dog');
        file_put_contents($this->instancePath . '/fileadmin/secret.jpg', 'outside');

        $connection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $connection->insert('sys_file_storage', [
            'uid'           => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $connection->insert('sys_filemounts', ['uid' => 1, 'pid' => 0, 'title' => 'Docs', 'identifier' => '1:/docs/', 'read_only' => 0]);
        $connection->insert('be_groups', [
            'uid'              => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: inherit db AND file mounts from groups. Live access, so the
        // live cases can run; the workspace case moves the editor.
        $connection->update('be_users', ['usergroup' => '9', 'options' => 3, 'workspace_perms' => 1, 'workspace_id' => 0], ['uid' => 2]);
        $connection->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);

        foreach ([
            [self::ROOT, 0, 'Subjects', 0, 0],
            [self::ANIMALS, self::ROOT, 'Animals', 0, 0],
            [self::DOGS, self::ANIMALS, 'Dogs', 0, 0],
            [self::LANDSCAPE, self::ROOT, 'Landscape', 0, 0],
            [self::HIDDEN, self::ROOT, 'Hidden', 1, 0],
            [self::OTHER_TREE, 0, 'Campaign 2026', 0, 0],
            [self::DELETED, self::ROOT, 'Deleted', 0, 1],
        ] as [$uid, $parent, $title, $hidden, $deleted]) {
            $connection->insert('sys_category', [
                'uid'     => $uid, 'pid' => 0, 'parent' => $parent, 'title' => $title, 'hidden' => $hidden,
                'deleted' => $deleted, 'sys_language_uid' => 0, 'sorting' => $uid * 16,
            ]);
        }

        $this->indexFile(self::FILE, '/docs/dog.jpg', 'dog.jpg');
        $this->indexFile(self::FILE_OUTSIDE_MOUNT, '/secret.jpg', 'secret.jpg');
        $connection->insert('sys_file_metadata', ['uid' => self::METADATA, 'pid' => 0, 'file' => self::FILE, 'sys_language_uid' => 0, 'categories' => 2]);
        $connection->insert('sys_file_metadata', ['uid' => 101, 'pid' => 0, 'file' => self::FILE_OUTSIDE_MOUNT, 'sys_language_uid' => 0]);
        // The file carries a category of another tree and one of this tree.
        foreach ([self::OTHER_TREE, self::LANDSCAPE] as $sorting => $category) {
            $connection->insert('sys_category_record_mm', [
                'uid_local' => $category, 'uid_foreign' => self::METADATA, 'tablenames' => 'sys_file_metadata',
                'fieldname' => 'categories', 'sorting' => 1, 'sorting_foreign' => $sorting + 1,
            ]);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function theListHoldsTheLiveVisibleCategoriesBelowTheRootInTreeOrder(): void
    {
        self::assertSame(
            [self::ANIMALS => 'Animals', self::LANDSCAPE => 'Landscape', self::DOGS => 'Dogs'],
            $this->vocabulary(self::ROOT)->forUser($this->editor()),
        );
    }

    /**
     * The model is told what it may choose, read for the user the run acts
     * for, and told plainly when there is nothing.
     */
    #[Test]
    public function thePromptNamesTheCategoriesTheUserMayChoose(): void
    {
        $action = new EditorAction('LLL:a', 'LLL:b', 'icon', ['sys_file'], ['categories']);

        $lines = implode("\n", $this->builder(self::ROOT)->promptLines($action, $this->editor()));
        self::assertStringContainsString('The categories, as uid: title: 2: Animals; 4: Landscape; 3: Dogs.', $lines);

        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['category_perms' => (string) self::OTHER_TREE], ['uid' => 2]);
        $lines = implode("\n", $this->builder(self::ROOT)->promptLines($action, $this->editor()));
        self::assertStringContainsString('there is no category you may choose', $lines);
        self::assertStringNotContainsString('Animals', $lines);
    }

    /**
     * The builder takes the list as an optional collaborator. Without it the
     * prompt of every run would say there is nothing to choose, and every test
     * that builds the builder by hand would stay green.
     */
    #[Test]
    public function theAutowiredCatalogueTellsTheModelTheList(): void
    {
        $catalogue = $this->getService(EditorActionCatalogueInterface::class);
        $builder   = (new ReflectionProperty(EditorActionCatalogue::class, 'vision'))->getValue($catalogue);

        self::assertInstanceOf(VisionActionBuilder::class, $builder);
        self::assertInstanceOf(CategoryVocabulary::class, (new ReflectionProperty(VisionActionBuilder::class, 'categories'))->getValue($builder));
    }

    #[Test]
    public function aChosenSetReplacesTheTreesCategoriesAndKeepsTheOthers(): void
    {
        $editor = $this->editor();
        $result = $this->tool(self::ROOT)->execute(['uid' => self::FILE, 'categories' => [self::DOGS]], ToolExecutionContext::fromBackendUser($editor));

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('"Campaign 2026, Dogs"', $result->content);
        self::assertSame([self::OTHER_TREE, self::DOGS], $this->categoriesOf(self::METADATA));
    }

    #[Test]
    public function anEmptySetRemovesOnlyTheTreesCategories(): void
    {
        $result = $this->tool(self::ROOT)->execute(['uid' => self::FILE, 'categories' => []], ToolExecutionContext::fromBackendUser($this->editor()));

        self::assertFalse($result->isError, $result->content);
        self::assertSame([self::OTHER_TREE], $this->categoriesOf(self::METADATA));
    }

    /**
     * Another tree, a hidden, a deleted category and the root itself are not
     * on the list; each refuses the call before any approval, and the refusal
     * names the list.
     */
    #[Test]
    public function aCategoryOutsideTheListIsRefusedBeforeTheCard(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editor());
        $tool    = $this->tool(self::ROOT);

        foreach ([self::OTHER_TREE, self::HIDDEN, self::DELETED, self::ROOT] as $outside) {
            $call    = ['uid' => self::FILE, 'categories' => [self::DOGS, $outside]];
            $refusal = $tool->refusalBeforeApproval($call, $context);

            self::assertNotNull($refusal, (string) $outside);
            self::assertTrue($refusal->isError);
            self::assertStringContainsString('2: Animals; 4: Landscape; 3: Dogs', $refusal->content);
            self::assertTrue($tool->execute($call, $context)->isError);
        }

        self::assertSame([self::OTHER_TREE, self::LANDSCAPE], $this->categoriesOf(self::METADATA));
    }

    #[Test]
    public function withoutARootEveryCallIsRefused(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editor());
        $refusal = $this->tool(0)->refusalBeforeApproval(['uid' => self::FILE, 'categories' => []], $context);

        self::assertNotNull($refusal);
        self::assertStringContainsString('vision.categories.rootUid', $refusal->content);
        self::assertSame([self::OTHER_TREE, self::LANDSCAPE], $this->categoriesOf(self::METADATA));
    }

    /**
     * Core's category tree shows an editor with mounts only what lies below
     * them, and the DataHandler checks nothing; the tool does.
     */
    #[Test]
    public function anEditorWithCategoryMountsChoosesBelowThemOnly(): void
    {
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['category_perms' => (string) self::ANIMALS], ['uid' => 2]);
        $editor = $this->editor();

        self::assertSame([self::ANIMALS => 'Animals', self::DOGS => 'Dogs'], $this->vocabulary(self::ROOT)->forUser($editor));
        self::assertNotNull($this->tool(self::ROOT)->refusalBeforeApproval(['uid' => self::FILE, 'categories' => [self::LANDSCAPE]], ToolExecutionContext::fromBackendUser($editor)));
        // Landscape is not the editor's to remove either: it stays.
        $this->tool(self::ROOT)->execute(['uid' => self::FILE, 'categories' => [self::DOGS]], ToolExecutionContext::fromBackendUser($editor));
        self::assertSame([self::OTHER_TREE, self::LANDSCAPE, self::DOGS], $this->categoriesOf(self::METADATA));
    }

    #[Test]
    public function aFileOutsideTheMountGetsTheNeutralRefusal(): void
    {
        $result = $this->tool(self::ROOT)->execute(['uid' => self::FILE_OUTSIDE_MOUNT, 'categories' => [self::DOGS]], ToolExecutionContext::fromBackendUser($this->editor()));

        self::assertTrue($result->isError);
        self::assertSame('Asset not found or not permitted.', $result->content);
    }

    #[Test]
    public function theApprovalCardIsInTheActingUsersLanguage(): void
    {
        $editor               = $this->editor();
        $editor->user['lang'] = 'de';

        $german = $this->tool(self::ROOT)->previewCall(['uid' => self::FILE, 'categories' => [self::DOGS]], ToolExecutionContext::fromBackendUser($editor));

        self::assertSame([
            'Dateikategorien ändern',
            'Datei: „dog.jpg“',
            'Sprache: Standardsprache',
            'Kategorien: aktuell „Campaign 2026, Landscape“, Vorschlag „Campaign 2026, Dogs“',
            sprintf('Technische Details: Datei UID %d, Tabelle sys_file_metadata, UID %d, Felder categories, Kategorie-UIDs 6,4 → 6,3', self::FILE, self::METADATA),
        ], $german);
        self::assertGermanEditorLines($german);
    }

    /**
     * In a draft workspace the relation goes onto the metadata's version, and
     * live keeps its categories until the workspace is published.
     */
    #[Test]
    public function inADraftWorkspaceTheCategoriesGoOntoTheVersion(): void
    {
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 0, 'workspace_id' => self::DRAFT], ['uid' => 2]);
        $editor = $this->editor();
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft');

        $result = $this->tool(self::ROOT)->execute(['uid' => self::FILE, 'categories' => [self::DOGS]], ToolExecutionContext::fromBackendUser($editor));

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft in the workspace "Entwurf"', $result->content);
        self::assertSame([self::OTHER_TREE, self::LANDSCAPE], $this->categoriesOf(self::METADATA), 'live changed');

        $version = $this->connectionPool->getConnectionForTable('sys_file_metadata')
            ->select(['uid'], 'sys_file_metadata', ['t3ver_oid' => self::METADATA, 't3ver_wsid' => self::DRAFT])
            ->fetchOne();
        self::assertIsNumeric($version, 'no workspace version was stored');
        self::assertSame([self::OTHER_TREE, self::DOGS], $this->categoriesOf((int) $version));
    }

    /**
     * Where an installation took the versioning off the metadata table, a
     * draft workspace would get the live row written; the call is refused
     * before the approval card, and nothing is written (ADR-262).
     */
    #[Test]
    public function inADraftWorkspaceAnUnversionedMetadataTableIsRefusedBeforeTheCard(): void
    {
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 0, 'workspace_id' => self::DRAFT], ['uid' => 2]);
        $context = ToolExecutionContext::fromBackendUser($this->editor());
        $call    = ['uid' => self::FILE, 'categories' => [self::DOGS]];
        $tool    = $this->tool(self::ROOT);

        self::assertNull($tool->refusalBeforeApproval($call, $context), 'positive control: a versioned table is not refused');

        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $metadata = $tca['sys_file_metadata'] ?? null;
        self::assertIsArray($metadata);
        $ctrl = $metadata['ctrl'] ?? null;
        self::assertIsArray($ctrl);
        $ctrl['versioningWS_alwaysAllowLiveEdit'] = true;
        $metadata['ctrl']                         = $ctrl;
        $tca['sys_file_metadata']                 = $metadata;
        $GLOBALS['TCA']                           = $tca;

        // A fresh instance: the same call on `$tool` reads as one remembered
        // value to static analysis, and the TCA changed in between.
        $refusal = $this->tool(self::ROOT)->refusalBeforeApproval($call, $context);
        self::assertNotNull($refusal);
        self::assertStringContainsString('the TCA of sys_file_metadata has no versioning or allows editing live', $refusal->content);
        self::assertTrue($tool->execute($call, $context)->isError);
        self::assertSame([self::OTHER_TREE, self::LANDSCAPE], $this->categoriesOf(self::METADATA));
    }

    /**
     * A fractional uid is not cast to the file below it: `"10.9"` names no
     * file, and file 10 keeps its categories.
     */
    #[Test]
    public function aFractionalFileUidIsRefusedAndNoFileIsWritten(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editor());
        $call    = ['uid' => self::FILE . '.9', 'categories' => [self::DOGS]];
        $tool    = $this->tool(self::ROOT);

        $result = $tool->execute($call, $context);
        self::assertTrue($result->isError);
        self::assertStringContainsString('"uid" must be the positive sys_file uid', $result->content);
        self::assertSame(['Refused: "uid" must be the positive sys_file uid of exactly one file.'], $tool->previewCall($call, $context));
        self::assertSame([self::OTHER_TREE, self::LANDSCAPE], $this->categoriesOf(self::METADATA));
    }

    private function editor(): BackendUserAuthentication
    {
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        $editor->groupData['tables_modify']      = 'sys_file_metadata';
        $editor->groupData['tables_select']      = 'sys_file_metadata,sys_category';
        $editor->groupData['non_exclude_fields'] = 'sys_file_metadata:categories';
        // The core StoragePermissionsAspect only attaches mounts and
        // permissions when the storage object is created in a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $editor;
    }

    private function tool(int $root): SetFileCategoriesTool
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        // Registered; built here with the root under test, the rest from the
        // container so the storage gate enforces mounts.
        self::assertInstanceOf(SetFileCategoriesTool::class, $registry->get('set_file_categories'));

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $translator = $this->get(ApprovalPreviewTranslator::class);
        self::assertInstanceOf(ApprovalPreviewTranslator::class, $translator);

        return new SetFileCategoriesTool($this->connectionPool, $gate, $translator, $this->vocabulary($root));
    }

    private function builder(int $root): VisionActionBuilder
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['vision' => ['categories' => ['rootUid' => (string) $root]]]);
        $settings = new VisionSettings($configuration);

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);

        return new VisionActionBuilder(
            $gate,
            self::createStub(ResourceFactory::class),
            self::createStub(ImageDownscalerInterface::class),
            $settings,
            new CategoryVocabulary($this->connectionPool, $settings),
        );
    }

    private function vocabulary(int $root): CategoryVocabulary
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['vision' => ['categories' => ['rootUid' => (string) $root]]]);

        return new CategoryVocabulary($this->connectionPool, new VisionSettings($configuration));
    }

    /**
     * @return list<int>
     */
    private function categoriesOf(int $metadataUid): array
    {
        $rows = $this->connectionPool->getConnectionForTable('sys_category_record_mm')
            ->select(['uid_local'], 'sys_category_record_mm', ['uid_foreign' => $metadataUid, 'tablenames' => 'sys_file_metadata', 'fieldname' => 'categories'], [], ['sorting_foreign' => 'ASC'])
            ->fetchFirstColumn();

        return array_map(intval(...), $rows);
    }

    private function indexFile(int $uid, string $identifier, string $name): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('sys_file', [
            'uid'             => $uid, 'pid' => 0, 'storage' => 1, 'identifier' => $identifier,
            'identifier_hash' => 'fixture-' . $uid, 'folder_hash' => 'fixture-folder-' . $uid, 'name' => $name,
            'extension'       => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 10,
            // 1 is the only record type the metadata TCA defines without
            // EXT:filemetadata; the tool does not read the file type.
            'type' => 1, 'missing' => 0,
        ]);
    }
}
