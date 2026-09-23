<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\WritesThroughDataHandlerTrait;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Every writer that addresses a page, a content element or a file reference
 * by uid refuses a WORKSPACE VERSION row (ADR-198).
 *
 * The writers run in the live workspace only, and in the live workspace the
 * DataHandler writes to whatever uid it is handed — a draft of workspace 5
 * included. Before this rule, `publish_record` cleared the hidden flag of a
 * workspace draft, `update_content_element` overwrote a draft's header,
 * `copy_record` copied a draft into live, and the approval card showed the
 * draft's header. So every writer is called here, as an ADMIN (the strongest
 * case: no permission stands in the way), against a new-in-workspace row and
 * a workspace version of a live row, and must refuse without touching a row
 * and without its preview naming the draft.
 */
#[CoversTrait(WritesThroughDataHandlerTrait::class)]
final class WorkspaceVersionRowsAreRefusedTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private const ROOT = 1;

    private const LIVE_PAGE = 2;

    private const NEW_PAGE_IN_WORKSPACE = 20;

    private const VERSION_OF_LIVE_PAGE = 21;

    private const LIVE_ELEMENT = 10;

    private const NEW_ELEMENT_IN_WORKSPACE = 30;

    private const VERSION_OF_LIVE_ELEMENT = 31;

    private const VERSION_OF_LIVE_REFERENCE = 41;

    /** What a draft carries and no refusal or preview may show. */
    private const SECRET = 'DRAFT-SECRET';

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::ROOT, 0, 'Root', 0, 0, 0],
            [self::LIVE_PAGE, self::ROOT, 'Live', 0, 0, 0],
            // A folder, so a record of the writer fixture may be stored there.
            [self::NEW_PAGE_IN_WORKSPACE, self::ROOT, self::SECRET . ' new page', 5, 0, 1],
            [self::VERSION_OF_LIVE_PAGE, self::ROOT, self::SECRET . ' page version', 5, self::LIVE_PAGE, 0],
        ] as [$uid, $pid, $title, $workspace, $original, $state]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'hidden' => 1,
                'doktype' => $uid === self::NEW_PAGE_IN_WORKSPACE ? 254 : 1,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::LIVE_ELEMENT, 'Live element', 0, 0, 0],
            [self::NEW_ELEMENT_IN_WORKSPACE, self::SECRET . ' new element', 5, 0, 1],
            [self::VERSION_OF_LIVE_ELEMENT, self::SECRET . ' element version', 5, self::LIVE_ELEMENT, 0],
        ] as [$uid, $header, $workspace, $original, $state]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => self::LIVE_PAGE, 'colPos' => 0, 'CType' => 'textmedia', 'header' => $header,
                'hidden' => 1, 'sys_language_uid' => 0, 'assets' => 1,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
            ]);
        }

        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => self::VERSION_OF_LIVE_REFERENCE, 'pid' => self::LIVE_PAGE, 'uid_local' => 1,
            'tablenames' => 'tt_content', 'uid_foreign' => self::LIVE_ELEMENT, 'fieldname' => 'assets',
            'sys_language_uid' => 0, 't3ver_wsid' => 5, 't3ver_oid' => 40, 't3ver_state' => 0,
        ]);

        // A file the two FAL writers accept, so their refusal can only come
        // from the workspace row and not from a missing file.
        $this->connectionPool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local', 'is_online' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 1, 'storage' => 1, 'identifier' => '/image.jpg', 'name' => 'image.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'size' => 8, 'sha1' => str_repeat('1', 40),
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function callsOnWorkspaceRows(): iterable
    {
        yield 'update_page_metadata, new page'      => ['update_page_metadata', ['uid' => self::NEW_PAGE_IN_WORKSPACE, 'title' => 'x']];
        yield 'update_page_metadata, page version'  => ['update_page_metadata', ['uid' => self::VERSION_OF_LIVE_PAGE, 'title' => 'x']];
        yield 'set_page_social_image, page version' => ['set_page_social_image', ['page' => self::VERSION_OF_LIVE_PAGE, 'field' => 'og_image', 'file' => 1]];
        yield 'attach_file, element version'       => ['attach_file_to_content_element', ['content_element' => self::VERSION_OF_LIVE_ELEMENT, 'file' => 1]];
        yield 'move_content_element, version'       => ['move_content_element', ['uid' => self::VERSION_OF_LIVE_ELEMENT, 'target_page' => self::LIVE_PAGE]];
        yield 'move_content_element, to new page'   => ['move_content_element', ['uid' => self::LIVE_ELEMENT, 'target_page' => self::NEW_PAGE_IN_WORKSPACE]];
        yield 'create_content_element_draft'        => ['create_content_element_draft', ['page' => self::NEW_PAGE_IN_WORKSPACE, 'type' => 'text', 'header' => 'x']];
        yield 'create_page_draft'                   => ['create_page_draft', ['parent' => self::VERSION_OF_LIVE_PAGE, 'title' => 'x']];
        yield 'create_translation_draft'            => ['create_translation_draft', ['table' => 'tt_content', 'uid' => self::VERSION_OF_LIVE_ELEMENT, 'language' => 1]];
        yield 'create_record_draft'                 => ['create_record_draft', ['table' => 'tx_writerfixture_variant', 'pid' => self::NEW_PAGE_IN_WORKSPACE, 'fields' => ['title' => 'x']]];
        yield 'publish_record, new element'         => ['publish_record', ['table' => 'tt_content', 'uid' => self::NEW_ELEMENT_IN_WORKSPACE]];
        yield 'publish_record, page version'        => ['publish_record', ['table' => 'pages', 'uid' => self::VERSION_OF_LIVE_PAGE]];
        yield 'update_content_element, version'     => ['update_content_element', ['uid' => self::VERSION_OF_LIVE_ELEMENT, 'fields' => ['header' => 'x']]];
        yield 'delete_record, element version'      => ['delete_record', ['table' => 'tt_content', 'uid' => self::VERSION_OF_LIVE_ELEMENT]];
        yield 'copy_record, element version'        => ['copy_record', ['table' => 'tt_content', 'uid' => self::VERSION_OF_LIVE_ELEMENT, 'target_page' => self::LIVE_PAGE]];
        yield 'copy_record, to a new page'          => ['copy_record', ['table' => 'tt_content', 'uid' => self::LIVE_ELEMENT, 'target_page' => self::NEW_PAGE_IN_WORKSPACE]];
        yield 'move_page, page version'             => ['move_page', ['uid' => self::VERSION_OF_LIVE_PAGE, 'parent' => self::LIVE_PAGE]];
        yield 'replace_file_reference, version'     => ['replace_file_reference', ['reference' => self::VERSION_OF_LIVE_REFERENCE, 'action' => 'remove']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('callsOnWorkspaceRows')]
    public function aWriterRefusesAWorkspaceVersionRowAndTouchesNothing(string $toolName, array $arguments): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($toolName);
        self::assertInstanceOf(ToolInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($toolName, true);

        $admin   = $this->setUpBackendUser(1);
        $before  = $this->snapshot();
        $context = ToolExecutionContext::fromBackendUser($admin);

        if ($tool instanceof ToolPreviewInterface) {
            $preview = implode("\n", $tool->previewCall($arguments, $context));
            self::assertStringNotContainsString(self::SECRET, $preview, 'the approval card must not show a draft');
        }

        $result = $tool->execute($arguments, $context);

        self::assertTrue($result->isError, $toolName . ' wrote: ' . $result->content);
        // The neutral refusal every writer gives a row it cannot find: the
        // workspace row is not there for it, rather than refused for a
        // reason that names it.
        self::assertMatchesRegularExpression('/not found|not permitted/i', $result->content, $toolName);
        self::assertStringNotContainsString(self::SECRET, $result->content);
        self::assertSame($before, $this->snapshot(), $toolName . ' changed a row although it refused');
    }

    /**
     * Every row of the three tables, all columns but the timestamps a refused
     * call could never touch anyway.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['pages', 'tt_content', 'sys_file_reference'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            /** @var list<array<string, mixed>> $rows */
            $rows             = $queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative();
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }
}
