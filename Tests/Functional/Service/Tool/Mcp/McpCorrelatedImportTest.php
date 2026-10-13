<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHealthRecorder;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpHttpTransport::class)]
#[CoversClass(McpClient::class)]
#[CoversClass(McpImportService::class)]
final class McpCorrelatedImportTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $pool;

    private McpServerRepository $servers;

    private McpToolRepository $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->pool = $pool;
        $this->servers = new McpServerRepository($pool);
        $this->tools = new McpToolRepository($pool);
    }

    private function server(): McpServerRecord
    {
        $db = $this->pool->getConnectionForTable('tx_nrllm_mcp_server');
        $db->insert(
            'tx_nrllm_mcp_server',
            [
                'identifier' => 'srv',
                'name' => 'Server',
                'url' => 'https://mcp.example.com/rpc',
                'data_class' => 'publicContent',
                'enabled' => 1,
                'import_status' => 'never_imported',
            ],
        );
        $server = $this->servers->findByUid((int)$db->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);
        return $server;
    }

    private function importer(McpTestServer $http): McpImportService
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);
        return new McpImportService(
            new McpClient(
                $transport,
                new McpHealthRecorder(
                    $this->servers,
                    new Context(),
                    new NullLogger(),
                ),
                $this->get(McpDeadlineFactory::class),
            ),
            $this->servers,
            $this->tools,
            new McpToolNameMapper(),
            new McpSchemaNormalizer(),
            new ToolRegistry(),
            new NullLogger(),
            new Context(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function tool(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Literal ' . $name,
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ];
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidFinalPages(): iterable
    {
        yield 'wrong ID' => ['{"jsonrpc":"2.0","id":"other","result":{"tools":[]}}'];
        yield 'ambiguous branches' => ['{"jsonrpc":"2.0","id":@ID@,"result":{"tools":[]},"error":null}'];
        yield 'array MCP result' => ['{"jsonrpc":"2.0","id":@ID@,"result":[]}'];
        yield 'rounded fractional code' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1.0000000000000001,"message":"SECRET_REMOTE_PAYLOAD"}}',
        ];
        yield 'duplicate SSE' => ['SSE'];
        yield 'oversized SSE' => ['OVERSIZED'];
    }

    #[Test]
    #[DataProvider('invalidFinalPages')]
    public function malformedFinalPageCannotUpdateHealthOrReconcileRealCatalogueRows(
        string $wire,
    ): void {
        $server = $this->server();
        $initial = $this
            ->importer(
                (new McpTestServer())->willHandshake()->willReturn(
                    ['tools' => [$this->tool('old_a'), $this->tool('old_b')]],
                ),
            )
            ->import($server);
        self::assertFalse($initial->refused);
        self::assertSame(2, $initial->imported);
        $db = $this->pool->getConnectionForTable('tx_nrllm_mcp_server');
        $db->update(
            'tx_nrllm_mcp_server',
            ['last_contact' => 1700000010, 'last_latency_ms' => 77],
            ['uid' => $server->uid],
        );
        $catalogue = $this->pool->getConnectionForTable('tx_nrllm_mcp_tool');
        $states = new ToolStateRepository($this->pool);
        $states->setEnabled('mcp_srv_old_a', true);
        $states->setEnabled('mcp_srv_old_b', true);

        $beforeStates = $states->overrides();
        self::assertSame(
            ['mcp_srv_old_a' => true, 'mcp_srv_old_b' => true],
            $beforeStates,
        );
        $before = $catalogue
            ->select(
                ['*'],
                'tx_nrllm_mcp_tool',
                ['server' => $server->uid],
                [],
                ['uid' => 'ASC'],
            )
            ->fetchAllAssociative();
        self::assertCount(2, $before);
        $server = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame(2, $server->toolCount);
        $http = (new McpTestServer())
            ->willHandshake('session-failure')
            ->willReturn(['tools' => [$this->tool('new_tool')], 'nextCursor' => 'last']);
        $http->willReturnRawForRequest(
            static function (array $request) use ($wire): string {
                $id = json_encode($request['id'], JSON_THROW_ON_ERROR);
                if ($wire === 'SSE' || $wire === 'OVERSIZED') {
                    $reply = 'data: {"jsonrpc":"2.0","id":' . $id . ',"result":{"tools":[]}}' . "\n\n";
                    return $reply . ($wire === 'OVERSIZED' ? ':' . str_repeat(' ', 2 * 1024 * 1024) . "\n\n" : '') . $reply;
                }

                return str_replace('@ID@', $id, $wire);
            },
            $wire === 'SSE' || $wire === 'OVERSIZED' ? 'text/event-stream' : 'application/json',
        );
        $report = $this->importer($http)->import($server);
        self::assertTrue($report->refused);
        self::assertSame(0, $report->imported);
        self::assertSame(0, $report->skipped);
        self::assertSame(0, $report->orphaned);
        $after = $catalogue
            ->select(
                ['*'],
                'tx_nrllm_mcp_tool',
                ['server' => $server->uid],
                [],
                ['uid' => 'ASC'],
            )
            ->fetchAllAssociative();
        self::assertSame(
            $before,
            $after,
            'Every persisted catalogue field and enabled row stays intact',
        );
        self::assertSame($beforeStates, $states->overrides());
        $afterServer = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $afterServer);
        self::assertSame(1700000010, $afterServer->lastContact);
        self::assertSame(77, $afterServer->lastLatencyMs);
        self::assertSame(2, $afterServer->toolCount);
        self::assertSame('error', $afterServer->importStatus);
        self::assertStringContainsString(
            'not a JSON-RPC result',
            $afterServer->importError,
        );
        self::assertStringNotContainsString(
            'SECRET_REMOTE_PAYLOAD',
            $afterServer->importError,
        );
        self::assertSame(
            [
                'initialize',
                'notifications/initialized',
                'tools/list',
                'tools/list',
            ],
            $http->methods(),
        );
    }

    #[Test]
    public function aHealthyMatchedFinalPageReconcilesAndRecordsRealHealth(): void
    {
        $server = $this->server();
        $http = (new McpTestServer())
            ->willHandshake('session-healthy')
            ->willReturn(['tools' => [$this->tool('first')], 'nextCursor' => 'last'])
            ->willReturn(['tools' => [$this->tool('second')]]);
        $report = $this->importer($http)->import($server);
        self::assertFalse($report->refused);
        self::assertSame(2, $report->imported);
        self::assertSame(
            ['mcp_srv_first', 'mcp_srv_second'],
            array_map(
                static fn(McpToolRecord $tool): string => $tool->toolName,
                $this->tools->findLiveByServer($server->uid),
            ),
        );
        $after = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertGreaterThan(0, $after->lastContact);
        self::assertSame(2, $after->toolCount);
        self::assertSame('ok', $after->importStatus);
    }
}
