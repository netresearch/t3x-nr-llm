<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHealthRecorder;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * Liveness reaches the row, and a failed write never reaches the caller (ADR-154).
 *
 * Functional because the whole claim is about a database column: the recorder
 * and the repository are `final readonly`, and "the operator sees when the
 * server last answered" is only true if an UPDATE happened. Only the HTTP
 * client is faked.
 */
#[CoversClass(McpHealthRecorder::class)]
#[CoversClass(McpServerRepository::class)]
final class McpHealthTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_mcp_server';

    private ConnectionPool $connectionPool;

    private McpServerRepository $servers;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->connectionPool = $connectionPool;
        $this->servers        = new McpServerRepository($connectionPool);
    }

    /**
     * A tool call is what a server does for weeks between imports, and it is
     * exactly the case the import stamp could not report.
     */
    #[Test]
    public function aToolCallStampsTheServerWithoutTouchingTheImportState(): void
    {
        $server = $this->insertServer(['import_status' => 'ok', 'last_imported' => 1_700_000_000, 'tool_count' => 3]);
        $fake   = (new McpTestServer())
            ->willTakeAtLeast(60)
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'done']]]);

        $this->clientFor($fake)->callTool($server, 'do_it', []);

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertGreaterThan(0, $after->lastContact, 'the contact was recorded');
        // The measurement, not a constant: the call took at least 60 ms and
        // the column has to say so. `>= 0` would pass for a hardcoded zero.
        self::assertGreaterThanOrEqual(50, $after->lastLatencyMs);
        self::assertLessThan(5_000, $after->lastLatencyMs);

        // The catalogue state is untouched: reaching a server says nothing
        // about whether its tool list is current.
        self::assertSame('ok', $after->importStatus);
        self::assertSame(1_700_000_000, $after->lastImported);
        self::assertSame(3, $after->toolCount);
        // tstamp stamps an operator edit; a machine observation is not one.
        self::assertSame(0, $after->tstamp);
    }

    /**
     * A catalogue walk is a contact too — this is the path
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpImportService} takes, which
     * calls `listTools()` and nothing else on the wire.
     */
    #[Test]
    public function aCatalogueWalkStampsTheServerOnce(): void
    {
        $server = $this->insertServer();
        $fake   = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => [['name' => 'a']], 'nextCursor' => 'p2'])
            ->willReturn(['tools' => [['name' => 'b']]]);

        $this->clientFor($fake)->listTools($server);

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertGreaterThan(0, $after->lastContact);
    }

    /**
     * The connection test is the whole point of the column: it must move the
     * contact date and leave everything else exactly as it found it.
     */
    #[Test]
    public function theConnectionTestStampsTheContactAndWritesNothingElse(): void
    {
        $server = $this->insertServer(['import_status' => 'error', 'import_error' => 'the previous import failed']);
        $fake   = (new McpTestServer())->willReturn(['protocolVersion' => '2025-06-18']);

        $report = $this->clientFor($fake)->ping($server);
        self::assertTrue($report->reachable);

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertGreaterThan(0, $after->lastContact);
        // The reason the last IMPORT failed survives a probe that succeeded.
        self::assertSame('error', $after->importStatus);
        self::assertSame('the previous import failed', $after->importError);

        $catalogue = $this->connectionPool
            ->getConnectionForTable('tx_nrllm_mcp_tool')
            ->count('uid', 'tx_nrllm_mcp_tool', []);
        self::assertSame(0, $catalogue, 'the connection test wrote no catalogue row');
    }

    /**
     * A server that never answered reads as never contacted, and a failed
     * probe does not invent one.
     */
    #[Test]
    public function anUnreachableServerIsNotStampedAsContacted(): void
    {
        $server = $this->insertServer();
        $fake   = (new McpTestServer())->willReturnRaw('', 500);

        $report = $this->clientFor($fake)->ping($server);
        self::assertFalse($report->reachable);

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame(0, $after->lastContact);
    }

    /**
     * The property the whole design hangs on: a health write that blows up
     * must not take the tool call with it. The repository is replaced by one
     * whose connection pool throws, which is what a locked table or an
     * unmigrated column looks like from here.
     */
    #[Test]
    public function aFailingHealthWriteDoesNotFailTheToolCall(): void
    {
        $server = $this->insertServer();
        $fake   = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'text', 'text' => 'still answered']]]);

        $throwing = $this->createMock(ConnectionPool::class);
        $throwing->method('getConnectionForTable')
            ->willThrowException(new RuntimeException('the table is gone', 1799990300));

        $client = new McpClient(
            $this->transportFor($fake),
            new McpHealthRecorder(new McpServerRepository($throwing), new Context(), new NullLogger()),
        );

        self::assertSame('still answered', $client->callTool($server, 'do_it', []));

        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame(0, $after->lastContact, 'the observation was lost, as intended');
    }

    private function clientFor(McpTestServer $fake): McpClient
    {
        return new McpClient(
            $this->transportFor($fake),
            new McpHealthRecorder($this->servers, new Context(), new NullLogger()),
        );
    }

    private function transportFor(McpTestServer $fake): McpHttpTransport
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return $transport;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertServer(array $overrides = []): McpServerRecord
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $overrides + [
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
            'last_contact'     => 0,
            'last_latency_ms'  => 0,
            'tstamp'           => 0,
            'crdate'           => 0,
            'deleted'          => 0,
            'hidden'           => 0,
        ]);

        $server = $this->servers->findByUid((int)$connection->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);

        return $server;
    }
}
