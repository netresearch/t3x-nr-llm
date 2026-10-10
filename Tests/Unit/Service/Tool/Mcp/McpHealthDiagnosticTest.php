<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use DateTimeImmutable;
use Error;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHealthRecorder;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpHealthRecorder::class)]
final class McpHealthDiagnosticTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('failureCases')]
    public function failedObservationRetainsTheSoleDiagnosticWithoutThrowing(
        string $storageKind,
        string $loggerKind,
    ): void {
        $original = $this->errorFor($storageKind, 'storage failure');
        $logger = new McpHealthDiagnosticLoggerFixture($loggerKind);
        $subject = $this->recorder($original, $logger);
        $caught = null;
        try {
            $subject->recordContact(McpTestServer::server('health'), 17);
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull(
            $caught,
            'A lost liveness observation must never throw, including its diagnostic.',
        );
        self::assertSame(
            [
                [
                    'level' => 'warning',
                    'message' => 'An MCP liveness observation could not be stored',
                    'context' => ['server' => 'health', 'exception' => $original],
                ],
            ],
            $logger->records,
        );
    }

    #[Test]
    #[DataProvider('operationFailureCases')]
    public function actualSuccessfulClientOperationSurvivesTheObservationAndLoggerFailure(
        string $storageKind,
        string $loggerKind,
        string $operation,
    ): void {
        $original = $this->errorFor($storageKind, 'storage failure');
        $logger = new McpHealthDiagnosticLoggerFixture($loggerKind);
        $http = new McpTestServer();
        if ($operation === 'ping') {
            $http->willReturn(['protocolVersion' => '2025-06-18']);
        } else {
            $http
                ->willHandshake()
                ->willReturn(
                    $operation === 'list' ? ['tools' => [['name' => 'read']]] : [
                        'content' => [['type' => 'text', 'text' => 'still answered']],
                    ],
                );
        }

        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);

        $client = new McpClient(
            $transport,
            $this->recorder($original, $logger),
            new McpDeadlineFactory(
                new FakeMcpClock(),
                self::createStub(ExtensionConfiguration::class),
            ),
        );
        $server = McpTestServer::server('health');
        $caught = null;
        $actual = null;
        try {
            $actual = match ($operation) {
                'ping' => $client->ping($server)->reachable,
                'list' => $client->listTools($server),
                default => $client->callTool($server, 'read', [])->text,
            };
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull(
            $caught,
            'A completed MCP operation must survive failed health diagnostics.',
        );
        self::assertSame(
            match ($operation) {
                'ping' => true,
                'list' => [['name' => 'read']],
                default => 'still answered',
            },
            $actual,
        );
        self::assertSame(
            $operation === 'ping' ? ['initialize', 'notifications/initialized'] : [
                'initialize',
                'notifications/initialized',
                $operation === 'list' ? 'tools/list' : 'tools/call',
            ],
            $http->methods(),
        );
        self::assertSame(
            [
                [
                    'level' => 'warning',
                    'message' => 'An MCP liveness observation could not be stored',
                    'context' => ['server' => 'health', 'exception' => $original],
                ],
            ],
            $logger->records,
        );
    }

    /**
     * @return iterable<string, array{string,string}>
     */
    public static function failureCases(): iterable
    {
        foreach (['runtime', 'error'] as $storageKind) {
            foreach (['recording', 'runtime', 'error'] as $loggerKind) {
                yield $storageKind . '/' . $loggerKind => [$storageKind, $loggerKind];
            }
        }
    }

    /**
     * @return iterable<string, array{string,string,string}>
     */
    public static function operationFailureCases(): iterable
    {
        foreach (self::failureCases() as $name => [$storageKind, $loggerKind]) {
            foreach (['ping', 'list', 'call'] as $operation) {
                yield $name . '/' . $operation => [$storageKind, $loggerKind, $operation];
            }
        }
    }

    private function errorFor(string $kind, string $message): Throwable
    {
        return $kind === 'error' ? new Error($message) : new RuntimeException($message);
    }

    private function recorder(
        Throwable $original,
        McpHealthDiagnosticLoggerFixture $logger,
    ): McpHealthRecorder {
        $connections = self::createMock(ConnectionPool::class);
        $connections
            ->expects(self::once())
            ->method('getConnectionForTable')
            ->with('tx_nrllm_mcp_server')
            ->willThrowException($original);
        $context = new Context();
        $context->setAspect(
            'date',
            new DateTimeAspect(
                new DateTimeImmutable('@1700000000'),
            ),
        );
        return new McpHealthRecorder(
            new McpServerRepository($connections),
            $context,
            $logger,
        );
    }
}

final class McpHealthDiagnosticLoggerFixture extends AbstractLogger
{
    /** @var list<array{level: mixed,message: string,context: array<mixed>}> */
    public array $records = [];

    public function __construct(private readonly string $mode) {}

    /**
     * @param array<mixed> $context
     */
    public function log(
        $level,
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->records[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
        if ($this->mode === 'runtime') {
            throw new RuntimeException('optional logger failure', 1823198789);
        }

        if ($this->mode === 'error') {
            throw new Error('optional logger Error', 1823198790);
        }
    }
}
