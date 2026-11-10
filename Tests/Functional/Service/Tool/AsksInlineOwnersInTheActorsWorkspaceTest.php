<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The records that hold a written record as an inline child (ADR-230) are
 * asked as the run's draft workspace shows them (ADR-274): an owner that
 * exists in that workspace only is asked like a live one.
 *
 * Two inline relations can claim the same pointer column, as two tables
 * holding content elements by one `foreign_field` do. Read live, an owner new
 * in the workspace was not found, and a live record of the other table with
 * the same uid counted as the holder: the owner the editor may not change was
 * never asked, and the card was shown.
 *
 * Fixture: `InlineOwnersInTheWorkspace.csv`. Element 10 and record 30 point
 * at uid 50; gallery 50 is live on page 1, which the editors may edit;
 * `tt_content` 50 and draftable 50 are new in the draft workspace on page 2,
 * where the editors may not edit content.
 */
#[CoversClass(ReplaceFileReferenceTool::class)]
#[CoversClass(SetFileReferenceAlternativeTextTool::class)]
#[CoversClass(AttachFileToRecordTool::class)]
final class AsksInlineOwnersInTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private const DRAFT = 1;

    private const OWNER_UID = 50;

    /** The page the editors may edit content on. */
    private const PAGE = 1;

    private const GALLERY = 'tx_writerfixture_gallery';

    private const DRAFTABLE = 'tx_writerfixture_draftable';

    private ConnectionPool $connectionPool;

    /** @var array<array-key, mixed> */
    private array $tca = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $this->importFixture('InlineOwnersInTheWorkspace.csv');
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin');
        file_put_contents($this->instancePath . '/fileadmin/one.jpg', 'first');
        file_put_contents($this->instancePath . '/fileadmin/two.jpg', 'second');

        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $this->tca = $tca;

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        // The core StoragePermissionsAspect attaches mounts only inside a
        // backend request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tca;
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * Per tool: the call, the table of the record it writes, the pointer
     * column, and the table whose owner 50 exists in the draft only.
     *
     * @return array<string, array{string, array<string, mixed>, string, string, string}>
     */
    public static function writers(): array
    {
        return [
            'replace_file_reference' => [
                'replace_file_reference', ['reference' => 40, 'action' => 'remove'],
                'tt_content', 'tx_writerfixture_parent', self::DRAFTABLE,
            ],
            'set_file_reference_alternative_text' => [
                'set_file_reference_alternative_text', ['reference' => 40, 'alternative' => 'A new text'],
                'tt_content', 'tx_writerfixture_parent', self::DRAFTABLE,
            ],
            'attach_file_to_record' => [
                'attach_file_to_record', ['table' => self::DRAFTABLE, 'record' => 30, 'file' => 2, 'field' => 'media'],
                self::DRAFTABLE, 'parent_content', 'tt_content',
            ],
        ];
    }

    /**
     * The live gallery 50, which the editor may edit, and the owner 50 new
     * in the draft on a page the editor may not edit content on both claim
     * the record. The owner new in the draft is asked: refused with the
     * neutral string, on the card and in the write, and nothing is written.
     *
     * @param array<string, mixed> $call
     */
    #[Test]
    #[DataProvider('writers')]
    public function aLiveOwnerWithTheSameUidDoesNotHideAnOwnerNewInTheWorkspace(string $name, array $call, string $child, string $pointer, string $draftOwner): void
    {
        $tool    = $this->tool($name);
        $context = ToolExecutionContext::fromBackendUser($this->editorInTheDraft());
        $draft   = $this->draftRows();

        $this->claimedBy([self::GALLERY], $child, $pointer);
        self::assertStringNotContainsString($this->notPermitted($name), $this->card($tool, $call, $context), 'positive control: the live gallery alone lets the editor');

        $this->claimedBy([self::GALLERY, $draftOwner], $child, $pointer);
        self::assertSame($this->notPermitted($name), $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);
        self::assertTrue($result->isError, $result->content);
        self::assertSame($this->notPermitted($name), $result->content);
        self::assertSame($draft, $this->draftRows(), 'the refused call wrote into the draft workspace');
    }

    /**
     * The owner new in the draft alone holds the record, on the page the
     * editor may edit: it is asked like a live owner and lets the editor.
     * Read live, it was not found, and the record counted as an orphan's.
     *
     * @param array<string, mixed> $call
     */
    #[Test]
    #[DataProvider('writers')]
    public function anOwnerNewInTheWorkspaceIsAskedLikeALiveOne(string $name, array $call, string $child, string $pointer, string $draftOwner): void
    {
        $tool    = $this->tool($name);
        $context = ToolExecutionContext::fromBackendUser($this->editorInTheDraft());
        $this->claimedBy([$draftOwner], $child, $pointer);

        // On page 2 the editor may not edit content: the owner is asked.
        self::assertSame($this->notPermitted($name), $this->card($tool, $call, $context), 'positive control: the owner new in the draft is asked');

        $this->connectionPool->getConnectionForTable($draftOwner)->update($draftOwner, ['pid' => self::PAGE], ['uid' => self::OWNER_UID]);
        $card = $this->card($tool, $call, $context);
        self::assertStringNotContainsString($this->notPermitted($name), $card);
        self::assertStringContainsString('Workspace: “Entwurf”', $card);

        $result = $tool->execute($call, $context);
        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('draft', $result->content);
    }

    /**
     * Per tool: the call, the table and pointer of the record it writes, and
     * the live owner on page 1 that the draft moves to page 2.
     *
     * @return array<string, array{string, array<string, mixed>, string, string, string, int}>
     */
    public static function movedOwners(): array
    {
        return [
            'replace_file_reference' => [
                'replace_file_reference', ['reference' => 40, 'action' => 'remove'],
                'tt_content', 'tx_writerfixture_parent', self::DRAFTABLE, 30,
            ],
            'set_file_reference_alternative_text' => [
                'set_file_reference_alternative_text', ['reference' => 40, 'alternative' => 'A new text'],
                'tt_content', 'tx_writerfixture_parent', self::DRAFTABLE, 30,
            ],
            'attach_file_to_record' => [
                'attach_file_to_record', ['table' => self::DRAFTABLE, 'record' => 30, 'file' => 2, 'field' => 'media'],
                self::DRAFTABLE, 'parent_content', 'tt_content', 10,
            ],
        ];
    }

    /**
     * A live owner on page 1, which the editor may edit, that the draft
     * workspace moves to page 2, where the editor may not edit content: the
     * page the owner has in the workspace is asked too, and refuses. Asked by
     * its stored row alone, the owner sat on the page it left and the card
     * was shown.
     *
     * @param array<string, mixed> $call
     */
    #[Test]
    #[DataProvider('movedOwners')]
    public function anOwnerMovedInTheWorkspaceIsAskedOnThePageItHasThere(string $name, array $call, string $child, string $pointer, string $ownerTable, int $owner): void
    {
        $tool = $this->tool($name);
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['tx_writerfixture_parent' => 30], ['uid' => 10]);
        $this->connectionPool->getConnectionForTable(self::DRAFTABLE)->update(self::DRAFTABLE, ['parent_content' => 10], ['uid' => 30]);
        $this->claimedBy([$ownerTable], $child, $pointer);
        self::assertStringNotContainsString($this->notPermitted($name), $this->card($tool, $call, ToolExecutionContext::fromBackendUser($this->editorInTheDraft())), 'positive control: the owner on page 1 lets the editor');

        $this->moveInTheDraft($ownerTable, $owner, 2);

        $context = ToolExecutionContext::fromBackendUser($this->editorInTheDraft());
        $draft   = $this->draftRows();
        self::assertSame($this->notPermitted($name), $this->card($tool, $call, $context));
        self::assertSame($this->notPermitted($name), $tool->execute($call, $context)->content);
        self::assertSame($draft, $this->draftRows(), 'the refused call wrote into the draft workspace');
    }

    /**
     * The other direction: the owner sits live on page 2, where the editor
     * may not edit content, and the draft moves it to page 1. Until the
     * workspace is published it stays on page 2 live, so that page is still
     * asked and refuses.
     *
     * @param array<string, mixed> $call
     */
    #[Test]
    #[DataProvider('movedOwners')]
    public function anOwnerMovedInTheWorkspaceIsStillAskedOnThePageItLeft(string $name, array $call, string $child, string $pointer, string $ownerTable, int $owner): void
    {
        $tool = $this->tool($name);
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['tx_writerfixture_parent' => 30], ['uid' => 10]);
        $this->connectionPool->getConnectionForTable(self::DRAFTABLE)->update(self::DRAFTABLE, ['parent_content' => 10], ['uid' => 30]);
        $this->claimedBy([$ownerTable], $child, $pointer);
        $this->connectionPool->getConnectionForTable($ownerTable)->update($ownerTable, ['pid' => 2], ['uid' => $owner]);
        $this->moveInTheDraft($ownerTable, $owner, self::PAGE);

        self::assertSame($this->notPermitted($name), $this->card($tool, $call, ToolExecutionContext::fromBackendUser($this->editorInTheDraft())));
    }

    /**
     * The chain is followed as the draft workspace shows it: element 10 is
     * held by record 30, and only the draft of record 30 points at its own
     * owner, `tt_content` 50 on the page the editor may not edit content on.
     * Followed by the stored live row, the walk ended at record 30 and the
     * card was shown.
     */
    #[Test]
    public function theChainFollowsAnOwnersPointerInTheWorkspace(): void
    {
        $tool    = $this->tool('replace_file_reference');
        $call    = ['reference' => 40, 'action' => 'remove'];
        $context = ToolExecutionContext::fromBackendUser($this->editorInTheDraft());
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['tx_writerfixture_parent' => 30], ['uid' => 10]);
        $this->connectionPool->getConnectionForTable(self::DRAFTABLE)->update(self::DRAFTABLE, ['parent_content' => 0], ['uid' => 30]);
        $GLOBALS['TCA'] = array_replace_recursive($this->tca, [
            self::DRAFTABLE => ['columns' => ['nrllm_children' => ['label' => 'Children', 'config' => ['type' => 'inline', 'foreign_table' => 'tt_content', 'foreign_field' => 'tx_writerfixture_parent']]]],
            'tt_content'    => ['columns' => ['nrllm_records' => ['label' => 'Records', 'config' => ['type' => 'inline', 'foreign_table' => self::DRAFTABLE, 'foreign_field' => 'parent_content']]]],
        ]);
        self::assertStringNotContainsString($this->notPermitted('replace_file_reference'), $this->card($tool, $call, $context), 'positive control: record 30 alone lets the editor');

        // Draft 31 of record 30 moves it below element 50.
        $this->importFixture('InlineOwnerDraftPointer.csv');
        $draft = $this->draftRows();

        self::assertSame($this->notPermitted('replace_file_reference'), $this->card($tool, $call, $context));
        self::assertSame($this->notPermitted('replace_file_reference'), $tool->execute($call, $context)->content);
        self::assertSame($draft, $this->draftRows(), 'the refused call wrote into the draft workspace');
    }

    /**
     * The move version core stores for a move in a workspace: a copy of the
     * record with the new pid, `t3ver_state` 4, pointing at the live record
     * (TYPO3 11 and later).
     */
    private function moveInTheDraft(string $table, int $uid, int $toPage): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $live = $queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('uid', $uid))->executeQuery()->fetchAssociative();
        self::assertIsArray($live);
        unset($live['uid']);
        $this->connectionPool->getConnectionForTable($table)->insert($table, ['pid' => $toPage, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => $uid, 't3ver_state' => 4] + $live);
    }

    /**
     * Declares on each owner table an inline column that holds `$child`
     * records by `$pointer`, the way EXT:news holds content elements, and on
     * no other table (ADR-230). {@see self::tearDown()} puts the TCA back.
     *
     * @param list<string> $owners
     */
    private function claimedBy(array $owners, string $child, string $pointer): void
    {
        $columns = [];
        foreach ($owners as $owner) {
            $columns[$owner] = ['columns' => ['nrllm_children' => [
                'label'  => 'Children',
                'config' => ['type' => 'inline', 'foreign_table' => $child, 'foreign_field' => $pointer],
            ]]];
        }

        $GLOBALS['TCA'] = array_replace_recursive($this->tca, $columns);
    }

    /**
     * The neutral refusal of each tool.
     */
    private function notPermitted(string $name): string
    {
        return match ($name) {
            'replace_file_reference'              => 'File reference, content element or file not found, or not permitted.',
            'set_file_reference_alternative_text' => 'File reference or its record not found, or not permitted.',
            default                               => 'Record or file not found, or not permitted.',
        };
    }

    /**
     * Every row of the draft workspace in the tables the tools write.
     *
     * @return list<array<string, mixed>>
     */
    private function draftRows(): array
    {
        $rows = [];
        foreach (['sys_file_reference', 'tt_content', self::DRAFTABLE] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', self::DRAFT))->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
                $rows[] = ['table' => $table] + $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $call
     */
    private function card(ToolInterface $tool, array $call, ToolExecutionContext $context): string
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        return implode("\n", $tool->previewCall($call, $context));
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
}
