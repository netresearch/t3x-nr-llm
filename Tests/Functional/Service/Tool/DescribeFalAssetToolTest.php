<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Tool\Builtin\DescribeFalAssetTool;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * `describe_fal_asset` against a real database, real file mounts and the core
 * TCA without EXT:filemetadata (spec 058, ADR-303). The EXT:filemetadata
 * columns are covered by {@see DescribeFalAssetFilemetadataTest}.
 */
#[CoversClass(DescribeFalAssetTool::class)]
final class DescribeFalAssetToolTest extends AbstractFunctionalTestCase
{
    use IndexesDescribedFilesTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    /** In the editor's `/docs/` mount, with a metadata record. */
    private const FILE_IN_MOUNT = 10;

    /** In the storage root, outside the editor's mount, with a metadata record. */
    private const FILE_OUTSIDE_MOUNT = 11;

    /** In the mount, never indexed into `sys_file_metadata`. */
    private const FILE_WITHOUT_METADATA = 12;

    /** No file carries this uid. */
    private const NO_SUCH_FILE = 999;

    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const DESCRIBED = <<<'TEXT'
        File [10] manual.txt, metadata in the default language:
        title: "Stored title"
        alternative: "Stored alt"
        description: "Stored description"
        width: 1920
        height: not set
        TEXT;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStorageWithEditorMount();

        $this->indexFile(self::FILE_IN_MOUNT, '/docs/manual.txt');
        $this->indexFile(self::FILE_OUTSIDE_MOUNT, '/top-secret.txt');
        $this->indexFile(self::FILE_WITHOUT_METADATA, '/docs/undescribed.txt');

        $this->indexMetadata(100, self::FILE_IN_MOUNT, [
            'title' => 'Stored title', 'alternative' => 'Stored alt', 'description' => 'Stored description',
            'width' => 1920, 'height' => 0,
        ]);
        $this->indexMetadata(101, self::FILE_OUTSIDE_MOUNT, [
            'title' => 'Secret plan', 'alternative' => 'Secret alt', 'description' => 'Do not tell',
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorReadsTheMetadataOfAFileInsideTheirMount(): void
    {
        $editor = $this->editor('sys_file_metadata:title');

        $result = $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($editor));

        self::assertFalse($result->isError);
        self::assertSame(self::DESCRIBED, $result->content);
    }

    /**
     * The existence oracle the neutral answer closes: a file outside the mount
     * (which HAS metadata), a uid no file carries, an invalid uid and a run
     * without a user all receive the same result, byte for byte.
     */
    #[Test]
    public function aFileOutsideTheMountAndAMissingUidGetTheSameBytes(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->editor('sys_file_metadata:title'));
        $tool    = $this->describeTool();

        $outside = $tool->execute(['uid' => self::FILE_OUTSIDE_MOUNT], $context);
        $missing = $tool->execute(['uid' => self::NO_SUCH_FILE], $context);

        self::assertSame(self::NOT_PERMITTED, $outside->content);
        self::assertSame($outside->content, $missing->content);
        self::assertSame($outside->isError, $missing->isError);
        self::assertEquals($missing, $outside, 'the whole result, not only its text');
        self::assertEquals($missing, $tool->execute(['uid' => 0], $context));
        self::assertEquals($missing, $tool->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::none()));
        self::assertStringNotContainsString('Secret', $outside->content);
    }

    /**
     * The ambient `$GLOBALS['BE_USER']` is an administrator; the run acts for
     * the editor. The editor's mounts decide.
     */
    #[Test]
    public function theActingUserDecidesNotTheAmbientOne(): void
    {
        $editor = $this->editor('sys_file_metadata:title');
        $admin  = $this->setUpBackendUser(1);
        self::assertSame($admin, $GLOBALS['BE_USER'], 'positive control: the ambient user is the admin');

        $context = ToolExecutionContext::fromBackendUser($editor);

        self::assertSame(self::NOT_PERMITTED, $this->describeTool()->execute(['uid' => self::FILE_OUTSIDE_MOUNT], $context)->content);
        self::assertSame(self::DESCRIBED, $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], $context)->content);
    }

    /**
     * Both directions: the admin-only `read_fal_asset_meta` is refused to the
     * editor by the runtime, while `describe_fal_asset` is offered to that same
     * editor and answers.
     */
    #[Test]
    public function readFalAssetMetaRefusesTheEditorWhomDescribeFalAssetAnswers(): void
    {
        $policy = $this->get(ToolCallPolicy::class);
        self::assertInstanceOf(ToolCallPolicy::class, $policy);
        $configuration = new LlmConfiguration();
        $editor        = $this->editor('sys_file_metadata:title');

        $read = $policy->decide('read_fal_asset_meta', $configuration, $editor);
        self::assertFalse($read->allowed);
        self::assertSame(ToolDenialReason::REQUIRES_ADMIN, $read->reason);

        $describe = $policy->decide('describe_fal_asset', $configuration, $editor);
        self::assertTrue($describe->allowed, $describe->reason->value);
        self::assertSame(self::DESCRIBED, $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($editor))->content);

        // Positive control: the refusal is about the admin flag. An admin is
        // past that gate (a later one, such as the trust zone of a configuration
        // without a provider, may still decide).
        $admin = $this->setUpBackendUser(1);
        self::assertNotSame(ToolDenialReason::REQUIRES_ADMIN, $policy->decide('read_fal_asset_meta', $configuration, $admin)->reason);
    }

    #[Test]
    public function withoutExtFilemetadataNoCopyrightLineAndNoError(): void
    {
        self::assertArrayNotHasKey('copyright', $this->metadataTca(), 'precondition: EXT:filemetadata is not loaded');

        $result = $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($this->admin()));

        self::assertFalse($result->isError);
        self::assertSame(self::DESCRIBED, $result->content);
        self::assertStringNotContainsString('copyright', $result->content);
    }

    /**
     * A file the editor may read but that carries no metadata record: every
     * field is "not set", and nothing is invented.
     */
    #[Test]
    public function anAccessibleFileWithoutMetadataIsNotSetEverywhere(): void
    {
        $result = $this->describeTool()->execute(
            ['uid' => self::FILE_WITHOUT_METADATA],
            ToolExecutionContext::fromBackendUser($this->editor('sys_file_metadata:title')),
        );

        self::assertSame(<<<'TEXT'
            File [12] undescribed.txt, metadata in the default language:
            title: not set
            alternative: not set
            description: not set
            width: not set
            height: not set
            TEXT, $result->content);
    }

    /**
     * A stored text is a JSON string literal: a line break in it cannot start
     * a line of its own, and a stored "not set" is not the marker.
     */
    #[Test]
    public function aStoredValueCannotForgeALine(): void
    {
        $this->pool()->getConnectionForTable('sys_file_metadata')->update(
            'sys_file_metadata',
            ['description' => "Harmless\ncopyright: free to use", 'alternative' => 'not set'],
            ['uid' => 100],
        );

        $content = $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($this->admin()))->content;

        self::assertStringContainsString('description: "Harmless\ncopyright: free to use"', $content);
        self::assertStringContainsString('alternative: "not set"', $content);
        self::assertDoesNotMatchRegularExpression('/^copyright:/m', $content);
    }

    /**
     * Core marks `title` as an exclude field. An editor without the grant gets
     * the marker, never the value; with the grant, the value. `description`
     * carries no exclude flag and needs no grant.
     */
    #[Test]
    public function aFieldWithoutTheGrantIsWithheld(): void
    {
        $content = $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($this->editor()))->content;

        self::assertStringContainsString("\ntitle: withheld (no field permission)\n", $content);
        self::assertStringNotContainsString('Stored title', $content);
        self::assertStringContainsString('description: "Stored description"', $content);
    }

    /**
     * In a run started in a draft workspace the metadata is read as that
     * workspace shows it; live reads the live row; another workspace's draft
     * is read by nobody else. The draft rows carry the LOWER uids on purpose.
     */
    #[Test]
    public function anotherWorkspacesDraftIsNeverRead(): void
    {
        $workspaces = $this->pool()->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Draft', 'members' => '']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);
        $this->indexMetadata(98, self::FILE_IN_MOUNT, ['title' => 'Draft title', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 100]);
        $this->indexMetadata(97, self::FILE_IN_MOUNT, ['title' => 'Other title', 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 100]);

        $titleIn = fn (int $workspace): string => (string) preg_replace(
            '/^.*\ntitle: ([^\n]*)\n.*$/s',
            '$1',
            $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($this->admin($workspace)))->content,
        );

        self::assertSame('"Draft title"', $titleIn(self::DRAFT));
        self::assertSame('"Other title"', $titleIn(self::OTHER_DRAFT));
        self::assertSame('"Stored title"', $titleIn(0));
    }

    /**
     * The default-language row is described, never a translation — which here
     * carries the LOWER uid, so an unpinned ordered query would pick it.
     */
    #[Test]
    public function aTranslationIsNeverReadInsteadOfTheDefaultRow(): void
    {
        $this->indexMetadata(96, self::FILE_IN_MOUNT, ['title' => 'Übersetzter Titel', 'sys_language_uid' => 1, 'l10n_parent' => 100]);

        $content = $this->describeTool()->execute(['uid' => self::FILE_IN_MOUNT], ToolExecutionContext::fromBackendUser($this->admin()))->content;

        self::assertSame(self::DESCRIBED, $content);
    }

    private function admin(int $workspace = 0): BackendUserAuthentication
    {
        $admin = $this->setUpBackendUser(1);
        if ($workspace > 0) {
            self::assertTrue($admin->setTemporaryWorkspace($workspace));
            self::assertSame($workspace, $admin->workspace, 'positive control: the admin works in the workspace');
        }

        return $admin;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metadataTca(): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $table = $tca['sys_file_metadata'] ?? null;
        self::assertIsArray($table);
        $columns = $table['columns'] ?? null;
        self::assertIsArray($columns);

        return $columns;
    }
}
