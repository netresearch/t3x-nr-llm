<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPageTreeTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for GetPageTreeTool.
 *
 * Builds a small `pages` tree and asserts depth-indented output, sorting,
 * and — load-bearing — that hidden and soft-deleted pages never reach the
 * model-facing outline.
 */
#[CoversClass(GetPageTreeTool::class)]
final class GetPageTreeToolTest extends AbstractFunctionalTestCase
{
    private GetPageTreeTool $tool;

    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();

        // get_pagetree self-enforces the acting backend user's page permissions
        // (ADR-038): set up an admin (BeUsers.csv uid 1) so getPagePermsClause
        // resolves to "show all" and the tool returns the full live tree here.
        $this->importFixture('BeUsers.csv');
        $this->backendUser = $this->setUpBackendUser(1);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new GetPageTreeTool($connectionPool);

        $connection = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $connection);
        $this->insertPage($connection, 1, 0, 'Home', 1, ['sorting' => 1]);
        $this->insertPage($connection, 2, 1, 'About', 1, ['sorting' => 1]);
        $this->insertPage($connection, 3, 1, 'Hidden Branch', 1, ['sorting' => 2, 'hidden' => 1]);
        $this->insertPage($connection, 4, 1, 'Deleted Branch', 1, ['sorting' => 3, 'deleted' => 1]);
        $this->insertPage($connection, 5, 2, 'Team', 1, ['sorting' => 1]);
    }

    #[Test]
    public function getSpecDeclaresGetPageTreeFunction(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('get_pagetree', $spec->name);
        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('rootUid', $properties);
        self::assertArrayHasKey('depth', $properties);
    }

    #[Test]
    public function returnsDepthIndentedLiveTree(): void
    {
        $output = $this->tool->execute(['rootUid' => 0, 'depth' => 3], $this->executionContext())->content;

        self::assertStringContainsString('[1] Home (doktype 1)', $output);
        // About is one level below Home, Team two levels below.
        self::assertStringContainsString('  [2] About (doktype 1)', $output);
        self::assertStringContainsString('    [5] Team (doktype 1)', $output);
    }

    #[Test]
    public function excludesHiddenAndDeletedPages(): void
    {
        $output = $this->tool->execute(['rootUid' => 1, 'depth' => 3], $this->executionContext())->content;

        self::assertStringNotContainsString('Hidden Branch', $output);
        self::assertStringNotContainsString('Deleted Branch', $output);
    }

    #[Test]
    public function excludesWorkspaceDraftPages(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $connection);
        // Unpublished workspace draft page (t3ver_wsid > 0) must not surface in
        // the tree sent to the LLM (it would also appear as a duplicate node).
        $connection->insert('pages', [
            'uid' => 6, 'pid' => 1, 'title' => 'Draft Branch', 'doktype' => 1, 'sorting' => 4,
            't3ver_wsid' => 1, 't3ver_oid' => 2, 't3ver_state' => 0,
        ]);

        $output = $this->tool->execute(['rootUid' => 1, 'depth' => 3], $this->executionContext())->content;

        self::assertStringNotContainsString('Draft Branch', $output);
        self::assertStringContainsString('[2] About', $output);
    }

    #[Test]
    public function depthOneStopsAtImmediateChildren(): void
    {
        $output = $this->tool->execute(['rootUid' => 1, 'depth' => 1], $this->executionContext())->content;

        self::assertStringContainsString('[2] About', $output);
        // Team is a grandchild — beyond depth 1.
        self::assertStringNotContainsString('Team', $output);
    }

    /**
     * Execution context carrying the acting admin backend user, so the tool
     * authorises exactly as it did via the former ambient $GLOBALS['BE_USER'].
     */
    private function executionContext(): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->backendUser);
    }

    /**
     * @param array{sorting?: int, hidden?: int, deleted?: int} $flags
     */
    private function insertPage(
        Connection $connection,
        int $uid,
        int $pid,
        string $title,
        int $doktype,
        array $flags = [],
    ): void {
        $connection->insert('pages', [
            'uid'     => $uid,
            'pid'     => $pid,
            'title'   => $title,
            'doktype' => $doktype,
            'sorting' => $flags['sorting'] ?? 0,
            'hidden'  => $flags['hidden'] ?? 0,
            'deleted' => $flags['deleted'] ?? 0,
        ]);
    }
}
