<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp;

use DateTimeImmutable;
use Error;
use Netresearch\NrLlm\Domain\ValueObject\McpImportReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpImportService::class)]
final class McpImportDiagnosticsTest extends AbstractFunctionalTestCase
{
    #[Test]
    #[DataProvider('diagnosticModes')]
    public function failedDiscoveryStillReturnsAndStoresItsRefusal(
        string $mode,
    ): void {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $servers = new McpServerRepository($pool);
        $catalogue = new McpToolRepository($pool);
        $connection = $pool->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert(
            'tx_nrllm_mcp_server',
            [
                'pid' => 0,
                'identifier' => 'diagnostic_server',
                'name' => 'Import diagnostic fixture',
                'url' => 'https://mcp.example.com/rpc',
                'data_class' => 'publicContent',
                'enabled' => 1,
                'import_status' => 'never_imported',
                'tool_count' => 0,
            ],
        );
        $uid = (int)$connection->lastInsertId();
        $server = $servers->findByUid($uid);
        self::assertInstanceOf(McpServerRecord::class, $server);
        $context = new Context();
        $context->setAspect(
            'date',
            new DateTimeAspect(new DateTimeImmutable('@1700000800')),
        );
        $advertised = (new McpTestServer())
            ->willHandshake()
            ->willReturn(
                [
                    'tools' => [
                        [
                            'name' => 'read',
                            'description' => 'An existing imported tool',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => ['id' => ['type' => 'integer']],
                            ],
                        ],
                    ],
                ],
            );
        $seed = $this
            ->importer($advertised, $servers, $catalogue, new NullLogger(), $context)
            ->import($server);
        self::assertFalse($seed->refused);
        self::assertSame(1, $seed->imported);
        $server = $servers->findByUid($uid);
        self::assertInstanceOf(McpServerRecord::class, $server);
        self::assertSame(1, $server->toolCount);
        self::assertSame('ok', $server->importStatus);
        $toolConnection = $pool->getConnectionForTable('tx_nrllm_mcp_tool');
        $before = $toolConnection
            ->select(['*'], 'tx_nrllm_mcp_tool', ['server' => $uid])
            ->fetchAllAssociative();
        self::assertCount(1, $before);

        $diagnosticFailure = match ($mode) {
            'runtime' => new RuntimeException('Diagnostic unavailable', 9701113),
            'error' => new Error('Diagnostic unavailable', 9701114),
            default => null,
        };
        $logger = new class ($diagnosticFailure) extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $attempts = [];

            public function __construct(private readonly ?Throwable $failure) {}

            /**
             * @param array<mixed> $context
             */
            public function log(
                mixed $level,
                string|Stringable $message,
                array $context = [],
            ): void {
                $this->attempts[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }
            }
        };
        $broken = (new McpTestServer())->willReturnRaw('gateway unavailable', 502);
        $context->setAspect(
            'date',
            new DateTimeAspect(new DateTimeImmutable('@1700000900')),
        );
        $report = null;
        $caught = null;
        try {
            $report = $this
                ->importer($broken, $servers, $catalogue, $logger, $context)
                ->import($server);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'An optional diagnostic must not replace the failed-discovery report.',
        );
        self::assertInstanceOf(McpImportReport::class, $report);
        self::assertTrue($report->refused);
        self::assertSame($uid, $report->serverUid);
        self::assertSame(0, $report->imported);
        self::assertSame(0, $report->skipped);
        self::assertSame(0, $report->orphaned);
        self::assertCount(1, $report->skipReasons);
        self::assertStringContainsString('502', $report->skipReasons[0]);
        self::assertSame(['initialize'], $broken->methods());
        self::assertSame(
            $before,
            $toolConnection
                ->select(['*'], 'tx_nrllm_mcp_tool', ['server' => $uid])
                ->fetchAllAssociative(),
        );
        $after = $servers->findByUid($uid);
        self::assertInstanceOf(McpServerRecord::class, $after);
        self::assertSame('error', $after->importStatus);
        self::assertSame($report->skipReasons[0], $after->importError);
        self::assertSame(1, $after->toolCount);
        self::assertSame(1700000900, $after->lastImported);
        self::assertSame(1700000900, $after->tstamp);
        self::assertCount(1, $logger->attempts);
        self::assertSame('warning', $logger->attempts[0]['level']);
        self::assertSame(
            'An MCP catalogue import failed',
            $logger->attempts[0]['message'],
        );
        self::assertSame(
            'diagnostic_server',
            $logger->attempts[0]['context']['server'],
        );
        self::assertInstanceOf(
            McpTransportException::class,
            $logger->attempts[0]['context']['exception'],
        );
        self::assertSame(
            $report->skipReasons[0],
            $logger->attempts[0]['context']['exception']->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function diagnosticModes(): iterable
    {
        yield 'recorded diagnostic' => ['record'];
        yield 'logger RuntimeException' => ['runtime'];
        yield 'logger Error' => ['error'];
    }

    private function importer(
        McpTestServer $http,
        McpServerRepository $servers,
        McpToolRepository $catalogue,
        LoggerInterface $logger,
        Context $context,
    ): McpImportService {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);

        $deadlineFactory = $this->get(McpDeadlineFactory::class);
        self::assertInstanceOf(McpDeadlineFactory::class, $deadlineFactory);

        return new McpImportService(
            new McpClient($transport, new RecordedContacts(), $deadlineFactory),
            $servers,
            $catalogue,
            new McpToolNameMapper(),
            new McpSchemaNormalizer(),
            new ToolRegistry(),
            $logger,
            $context,
        );
    }
}
