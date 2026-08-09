<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The two MCP tables against a real database (ADR-116).
 *
 * The reconciliation rules are the reason this is functional rather than a
 * unit test: they are statements about rows surviving an import, about uids
 * staying stable, and about the deleted flag — none of which a double can
 * demonstrate.
 */
#[CoversClass(McpServerRepository::class)]
#[CoversClass(McpToolRepository::class)]
final class McpCatalogueTest extends AbstractFunctionalTestCase
{
    private McpServerRepository $servers;

    private McpToolRepository $tools;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->connectionPool = $connectionPool;
        $this->servers        = new McpServerRepository($connectionPool);
        $this->tools          = new McpToolRepository($connectionPool);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertServer(array $overrides = []): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert('tx_nrllm_mcp_server', $overrides + [
            'pid'              => 0,
            'identifier'       => 'srv',
            'name'             => 'A server',
            'description'      => '',
            'url'              => 'https://mcp.example.com/rpc',
            'auth_credential'  => '',
            'auth_placement'   => 'bearer',
            'auth_header_name' => '',
            'data_class'       => 'publicContent',
            'enabled'          => 1,
            'import_status'    => 'never_imported',
            'import_error'     => '',
            'last_imported'    => 0,
            'tool_count'       => 0,
            'tstamp'           => 0,
            'crdate'           => 0,
            'deleted'          => 0,
            'hidden'           => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array{toolName: string, remoteName: string, description: string, inputSchema: string, remoteAnnotations: string}>
     */
    private function catalogue(string ...$names): array
    {
        return array_values(array_map(static fn(string $name): array => [
            'toolName'          => 'mcp_srv_' . $name,
            'remoteName'        => $name,
            'description'       => 'does ' . $name,
            'inputSchema'       => '{"type":"object","properties":{}}',
            'remoteAnnotations' => '',
        ], $names));
    }

    #[Test]
    public function readsAServerBackAsATypedRecord(): void
    {
        $uid = $this->insertServer(['identifier' => 'files', 'name' => 'File server']);

        $server = $this->servers->findByUid($uid);

        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame('files', $server->identifier);
        self::assertSame('File server', $server->name);
        self::assertTrue($server->enabled);
    }

    /**
     * A discarded server must disappear from the tool surface entirely.
     */
    #[Test]
    public function aDeletedServerIsInvisible(): void
    {
        $this->insertServer(['deleted' => 1]);

        self::assertSame([], $this->servers->findAll());
    }

    /**
     * `enabled` is this table's own switch and the one an operator reasons
     * about; honouring `hidden` as well would give one decision two controls
     * that can disagree.
     */
    #[Test]
    public function aHiddenServerStaysVisibleBecauseEnabledIsTheSwitch(): void
    {
        $this->insertServer(['hidden' => 1]);

        self::assertCount(1, $this->servers->findAll());
        self::assertCount(1, $this->servers->findEnabled());
    }

    /**
     * An undeclared data class makes a server inert rather than defaulted:
     * a remote server's egress sensitivity cannot be inferred.
     */
    #[Test]
    public function aServerWithoutADeclaredDataClassIsNotUsable(): void
    {
        $this->insertServer(['identifier' => 'declared', 'data_class' => 'publicContent']);
        $this->insertServer(['identifier' => 'undeclared', 'data_class' => '']);

        $usable = $this->servers->findUsable();

        self::assertSame(['declared'], array_map(static fn(McpServerRecord $s): string => $s->identifier, $usable));
        self::assertCount(2, $this->servers->findEnabled(), 'both are still enabled — only classification differs');
    }

    /**
     * The approval flag is an operator declaration like the data class, and a
     * server nobody has judged asks first: the column default is "required",
     * and only an explicit 0 turns it off (ADR-134).
     */
    #[Test]
    public function aServerRequiresApprovalUnlessTheOperatorTurnedItOff(): void
    {
        // No requires_approval in the insert — this is what a new row gets.
        $defaulted = $this->insertServer(['identifier' => 'defaulted']);
        $declined  = $this->insertServer(['identifier' => 'declined', 'requires_approval' => 0]);

        $defaultedServer = $this->servers->findByUid($defaulted);
        $declinedServer  = $this->servers->findByUid($declined);

        self::assertInstanceOf(McpServerRecord::class, $defaultedServer);
        self::assertInstanceOf(McpServerRecord::class, $declinedServer);
        self::assertTrue($defaultedServer->approvalRequired());
        self::assertFalse($declinedServer->approvalRequired());
    }

    #[Test]
    public function anImportWritesTheCatalogue(): void
    {
        $uid = $this->insertServer();

        $orphaned = $this->tools->reconcile($uid, 0, $this->catalogue('read', 'write'), 1000);

        self::assertSame(0, $orphaned);
        self::assertSame(
            ['mcp_srv_read', 'mcp_srv_write'],
            array_map(static fn(object $t): string => $t->toolName, $this->tools->findLiveByServer($uid)),
        );
    }

    /**
     * A row's uid is what per-tool state would hang off, so a second import
     * must update in place rather than recreate.
     */
    #[Test]
    public function aSecondImportKeepsTheExistingRowIdentity(): void
    {
        $uid = $this->insertServer();
        $this->tools->reconcile($uid, 0, $this->catalogue('read'), 1000);
        $liveBefore = $this->tools->findLiveByServer($uid);
        self::assertCount(1, $liveBefore);
        $first = $liveBefore[0];

        $this->tools->reconcile($uid, 0, $this->catalogue('read'), 2000);
        $live = $this->tools->findLiveByServer($uid);
        self::assertCount(1, $live);
        $second = $live[0];

        self::assertSame($first->uid, $second->uid);
        self::assertSame(2000, $second->tstamp);
        self::assertCount(1, $this->tools->findAllByServer($uid));
    }

    /**
     * Marked, not deleted: an operator who enabled a tool needs to see that it
     * went away, and a deleted row looks like a tool that never existed.
     */
    #[Test]
    public function aToolThatVanishedIsMarkedRatherThanRemoved(): void
    {
        $uid = $this->insertServer();
        $this->tools->reconcile($uid, 0, $this->catalogue('read', 'write'), 1000);

        $orphaned = $this->tools->reconcile($uid, 0, $this->catalogue('read'), 2000);

        self::assertSame(1, $orphaned);
        self::assertCount(1, $this->tools->findLiveByServer($uid));
        self::assertCount(2, $this->tools->findAllByServer($uid));

        $all  = $this->tools->findAllByServer($uid);
        $gone = array_values(array_filter($all, static fn(object $t): bool => $t->orphaned));
        self::assertCount(1, $gone);
        self::assertSame('mcp_srv_write', $gone[0]->toolName);
    }

    #[Test]
    public function aToolThatComesBackIsRevivedNotDuplicated(): void
    {
        $uid = $this->insertServer();
        $this->tools->reconcile($uid, 0, $this->catalogue('read', 'write'), 1000);
        $this->tools->reconcile($uid, 0, $this->catalogue('read'), 2000);

        $this->tools->reconcile($uid, 0, $this->catalogue('read', 'write'), 3000);

        self::assertCount(2, $this->tools->findLiveByServer($uid));
        self::assertCount(2, $this->tools->findAllByServer($uid));
    }

    /**
     * A row already marked keeps the timestamp that says when the tool actually
     * disappeared, rather than being rewritten on every later import.
     */
    #[Test]
    public function anAlreadyOrphanedRowKeepsTheTimestampOfItsDisappearance(): void
    {
        $uid = $this->insertServer();
        $this->tools->reconcile($uid, 0, $this->catalogue('read', 'write'), 1000);
        $this->tools->reconcile($uid, 0, $this->catalogue('read'), 2000);

        $orphaned = $this->tools->reconcile($uid, 0, $this->catalogue('read'), 3000);

        $gone = array_values(array_filter(
            $this->tools->findAllByServer($uid),
            static fn(object $t): bool => $t->orphaned,
        ));

        self::assertSame(1, $orphaned, 'it is still counted as missing');
        self::assertCount(1, $gone);
        self::assertSame(2000, $gone[0]->tstamp, 'but not re-stamped');
    }

    #[Test]
    public function oneServersCatalogueDoesNotDisturbAnothers(): void
    {
        $first  = $this->insertServer(['identifier' => 'one']);
        $second = $this->insertServer(['identifier' => 'two']);

        $this->tools->reconcile($first, 0, [[
            'toolName'          => 'mcp_one_read',
            'remoteName'        => 'read',
            'description'       => '',
            'inputSchema'       => '{}',
            'remoteAnnotations' => '',
        ]], 1000);
        $this->tools->reconcile($second, 0, [[
            'toolName'          => 'mcp_two_read',
            'remoteName'        => 'read',
            'description'       => '',
            'inputSchema'       => '{}',
            'remoteAnnotations' => '',
        ]], 1000);

        self::assertCount(1, $this->tools->findLiveByServer($first));
        self::assertCount(1, $this->tools->findLiveByServer($second));
    }

    #[Test]
    public function recordsHowTheLastImportWent(): void
    {
        $uid = $this->insertServer();

        $this->servers->recordImportOutcome($uid, 'error', 'the host refused', 0, 4242);

        $server = $this->servers->findByUid($uid);
        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame('error', $server->importStatus);
        self::assertSame('the host refused', $server->importError);
        self::assertSame(4242, $server->lastImported);
    }

    /**
     * A server that recovers must stop showing the fault that has been fixed.
     */
    #[Test]
    public function aRecoveredServerClearsItsRecordedError(): void
    {
        $uid = $this->insertServer();
        $this->servers->recordImportOutcome($uid, 'error', 'the host refused', 0, 1000);

        $this->servers->recordImportOutcome($uid, 'ok', '', 3, 2000);

        $server = $this->servers->findByUid($uid);
        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame('', $server->importError);
        self::assertSame(3, $server->toolCount);
    }
}
