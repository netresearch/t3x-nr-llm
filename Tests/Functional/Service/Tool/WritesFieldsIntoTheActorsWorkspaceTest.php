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
        self::assertStringContainsString('is not hidden; nothing was written', $result->content);
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
