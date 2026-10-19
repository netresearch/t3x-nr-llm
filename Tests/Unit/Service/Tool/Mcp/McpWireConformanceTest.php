<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Closure;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpHttpTransport::class)]
#[CoversClass(McpClient::class)]
final class McpWireConformanceTest extends AbstractUnitTestCase
{
    private function transport(ClientInterface $http): McpHttpTransport
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($http);
        return $transport;
    }

    /**
     * @return array{response: array<string, mixed>|null, error: Throwable|null}
     */
    private function exchange(McpTestServer $http): array
    {
        $response = null;
        $error = null;
        try {
            $response = $this
                ->transport($http)
                ->call(
                    McpTestServer::server(),
                    'tools/list',
                    [],
                    McpOperationDeadline::start(new FakeMcpClock(), 20),
                );
        } catch (Throwable $caught) {
            $error = $caught;
        }

        return ['response' => $response, 'error' => $error];
    }

    /**
     * @return Closure(array<string, mixed>): string
     */
    private function wire(string $template): Closure
    {
        return static fn(array $request): string => str_replace(
            '@ID@',
            json_encode($request['id'], JSON_THROW_ON_ERROR),
            $template,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvelopes(): iterable
    {
        yield 'missing version' => ['{"id":@ID@,"result":{}}'];
        yield 'wrong version' => ['{"jsonrpc":"1.0","id":@ID@,"result":{}}'];
        yield 'numeric version' => ['{"jsonrpc":2.0,"id":@ID@,"result":{}}'];
        yield 'missing id' => ['{"jsonrpc":"2.0","result":{}}'];
        yield 'different id' => ['{"jsonrpc":"2.0","id":"other","result":{}}'];
        yield 'numeric id' => ['{"jsonrpc":"2.0","id":1,"result":{}}'];
        yield 'float id' => ['{"jsonrpc":"2.0","id":1.0,"result":{}}'];
        yield 'null id' => ['{"jsonrpc":"2.0","id":null,"result":{}}'];
        yield 'boolean id' => ['{"jsonrpc":"2.0","id":true,"result":{}}'];
        yield 'object id' => ['{"jsonrpc":"2.0","id":{},"result":{}}'];
        yield 'mixed request response' => ['{"jsonrpc":"2.0","id":@ID@,"method":"server/request","result":{}}'];
        yield 'both branches' => [
            '{"jsonrpc":"2.0","id":@ID@,"result":{},"error":{"code":-1,"message":"bad"}}',
        ];
        yield 'null error alongside result' => ['{"jsonrpc":"2.0","id":@ID@,"result":{},"error":null}'];
        yield 'null result alongside error' => [
            '{"jsonrpc":"2.0","id":@ID@,"result":null,"error":{"code":-1,"message":"bad"}}',
        ];
        yield 'neither branch' => ['{"jsonrpc":"2.0","id":@ID@}'];
        yield 'root array' => ['[{"jsonrpc":"2.0","id":@ID@,"result":{}}]'];
        yield 'root null' => ['null'];
        yield 'root scalar' => ['99'];
        yield 'empty result array' => ['{"jsonrpc":"2.0","id":@ID@,"result":[]}'];
        yield 'result list' => ['{"jsonrpc":"2.0","id":@ID@,"result":[{}]}'];
        yield 'result scalar' => ['{"jsonrpc":"2.0","id":@ID@,"result":4}'];
        yield 'error list' => ['{"jsonrpc":"2.0","id":@ID@,"error":[]}'];
        yield 'missing code' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"message":"bad"}}'];
        yield 'string code' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"code":"1","message":"bad"}}'];
        yield 'boolean code' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"code":true,"message":"bad"}}'];
        yield 'fraction code' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"code":1.5,"message":"bad"}}'];
        yield 'rounded fraction code' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1.0000000000000001,"message":"bad"}}',
        ];
        yield 'numeric message' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"code":1,"message":7}}'];
        yield 'missing message' => ['{"jsonrpc":"2.0","id":@ID@,"error":{"code":1}}'];
        yield 'overflow integer' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":' . PHP_INT_MAX . '0,"message":"bad"}}',
        ];
        yield 'overflow exponent' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1e999999999999999999999999999,"message":"bad"}}',
        ];
        yield 'underflow nonzero' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1e-99999999999999999999999999,"message":"bad"}}',
        ];
        yield 'escaped code selected fraction' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1,"co\u0064e":1.0000000000000001,"message":"bad"}}',
        ];
        yield 'duplicate error selected malformed' => [
            '{"jsonrpc":"2.0","id":@ID@,"error":{"code":1,"message":"ok"},"err\u006fr":{"code":true,"message":"bad"}}',
        ];
        yield 'text not json' => ['SECRET_REMOTE_PAYLOAD'];
    }

    #[Test]
    #[DataProvider('invalidEnvelopes')]
    public function invalidReplyIsTypedSanitizedMalformed(
        string $template,
    ): void {
        $http = (new McpTestServer())->willReturnRawForRequest($this->wire($template));
        $actual = $this->exchange($http);
        self::assertInstanceOf(McpTransportException::class, $actual['error']);
        self::assertSame(1799990213, $actual['error']->getCode());
        self::assertNull($actual['response']);
        self::assertStringNotContainsString(
            'SECRET_REMOTE_PAYLOAD',
            $actual['error']->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function integralErrorCodes(): iterable
    {
        yield 'integer' => ['-32601', -32601];
        yield 'decimal whole' => ['1.0', 1];
        yield 'exponent whole' => ['1e0', 1];
        yield 'fraction then exponent' => ['1.25e2', 125];
        yield 'trailing zero fraction' => ['120.00e-1', 12];
        yield 'negative zero' => ['-0.0', 0];
        yield 'huge zero exponent' => ['0e9999999999999999999999999', 0];
        yield 'tiny zero exponent' => ['-0e-999999999999999999999999', 0];
        yield 'maximum exact' => [PHP_INT_MAX . '.0', PHP_INT_MAX];
        yield 'minimum exact' => [PHP_INT_MIN . '.0', PHP_INT_MIN];
        yield 'escaped final code wins' => ['1,"co\u0064e":1.0', 1];
        yield 'nested fake code ignored' => ['1,"data":{"code":1.5,"message":"wrong"}', 1];
        yield 'strings brackets ignored' => ['1,"data":"},\"code\":1.5,{"', 1];
    }

    #[Test]
    #[DataProvider('integralErrorCodes')]
    public function integralJsonNumberKeepsItsActualRemoteCode(
        string $token,
        int $expected,
    ): void {
        $http = (new McpTestServer())->willReturnRawForRequest(
            $this->wire('{"jsonrpc":"2.0","id":@ID@,"error":{"code":' . $token . ',"message":"literal remote fault"}}'),
        );
        $actual = $this->exchange($http);
        self::assertInstanceOf(McpTransportException::class, $actual['error']);
        self::assertSame(1799990214, $actual['error']->getCode());
        self::assertStringContainsString(
            'JSON-RPC error ' . $expected . ':',
            $actual['error']->getMessage(),
        );
        self::assertStringContainsString(
            'literal remote fault',
            $actual['error']->getMessage(),
        );
        self::assertNull($actual['response']);
    }

    #[Test]
    public function literalMcpObjectAndEmptyObjectKeepTheArrayContract(): void
    {
        foreach ([
            '{}' => [],
            '{"tools":[],"nested":{"items":[1,2]}}' => ['tools' => [], 'nested' => ['items' => [1, 2]]],
        ] as $wire => $expected) {
            $actual = $this->exchange(
                (new McpTestServer())->willReturnRawForRequest(
                    $this->wire('{"jsonrpc":"2.0","id":@ID@,"result":' . $wire . '}'),
                ),
            );
            self::assertNull($actual['error']);
            self::assertSame($expected, $actual['response']['result'] ?? null);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStreams(): iterable
    {
        $ok = 'data: {"jsonrpc":"2.0","id":@ID@,"result":{"ok":true}}' . "\n\n";
        $wrong = 'data: {"jsonrpc":"2.0","id":"other","result":{"ok":false}}' . "\n\n";
        $error = 'data: {"jsonrpc":"2.0","id":@ID@,"error":{"code":-1,"message":"remote"}}' . "\n\n";
        yield 'unrelated then matched' => [$wrong . $ok];
        yield 'matched then unrelated' => [$ok . $wrong];
        yield 'duplicate result' => [$ok . $ok];
        yield 'error then duplicate' => [$error . $ok];
        yield 'result then error' => [$ok . $error];
        yield 'mixed notification result' => [
            'data: {"jsonrpc":"2.0","id":@ID@,"method":"server/call","result":{}}' . "\n\n" . $ok,
        ];
        yield 'versionless response' => ['data: {"id":@ID@,"result":{}}' . "\n\n" . $ok];
        yield 'no response' => ['data: {"jsonrpc":"2.0","method":"notify"}' . "\n\n"];
    }

    #[Test]
    #[DataProvider('invalidStreams')]
    public function completeStreamCannotHideAnotherOrMalformedReply(
        string $stream,
    ): void {
        $actual = $this->exchange(
            (new McpTestServer())->willReturnRawForRequest(
                $this->wire($stream),
                'text/event-stream',
            ),
        );
        self::assertInstanceOf(McpTransportException::class, $actual['error']);
        self::assertSame(1799990213, $actual['error']->getCode());
        self::assertNull($actual['response']);
    }

    #[Test]
    public function oneCompleteStreamIgnoresNotificationsAndPreservesMultilineData(): void
    {
        $template = ": comment\r\n\r\n" . 'data: {"jsonrpc":"2.0","method":"server/request","id":99,"params":{}}' . "\r\n\r\n" . 'event: message' . "\r\n" . 'data: {"jsonrpc":"2.0","id":@ID@,' . "\r\n" . 'data: "result":{"literal":"requested"}}' . "\r\n\r\n";
        $actual = $this->exchange(
            (new McpTestServer())->willReturnRawForRequest(
                $this->wire($template),
                'text/event-stream; charset=utf-8',
            ),
        );
        self::assertNull($actual['error']);
        self::assertSame(
            ['literal' => 'requested'],
            $actual['response']['result'] ?? null,
        );
    }

    #[Test]
    public function oversizedStreamNeverAcceptsThePrefix(): void
    {
        $first = 'data: {"jsonrpc":"2.0","id":@ID@,"result":{"marker":"prefix"}}' . "\n\n";
        $template = $first . ':' . str_repeat(' ', 2 * 1024 * 1024) . "\n\n" . $first;
        $actual = $this->exchange(
            (new McpTestServer())->willReturnRawForRequest(
                $this->wire($template),
                'text/event-stream',
            ),
        );
        self::assertInstanceOf(McpTransportException::class, $actual['error']);
        self::assertSame(1799990213, $actual['error']->getCode());
        self::assertNull($actual['response']);
    }

    #[Test]
    public function exactlyAtLimitCompleteJsonIsAccepted(): void
    {
        $http = (new McpTestServer())->willReturnRawForRequest(
            static function (array $request): string {
                $reply = json_encode(
                    [
                        'jsonrpc' => '2.0',
                        'id' => $request['id'],
                        'result' => ['marker' => 'limit'],
                    ],
                    JSON_THROW_ON_ERROR,
                );
                return $reply . str_repeat(' ', 2 * 1024 * 1024 - strlen($reply));
            },
        );
        $actual = $this->exchange($http);
        self::assertNull($actual['error']);
        self::assertSame(
            ['marker' => 'limit'],
            $actual['response']['result'] ?? null,
        );
    }

    #[Test]
    public function stalledReadBeforeEofDoesNotAcceptACompleteLookingPrefix(): void
    {
        $http = (new McpTestServer())->willRespond(
            static function (
                RequestInterface $request,
            ): ResponseInterface {
                $decoded = json_decode(
                    (string)$request->getBody(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $stream = self::createStub(StreamInterface::class);
                $stream->method('eof')->willReturn(false);
                $stream
                    ->method('read')
                    ->willReturnOnConsecutiveCalls(
                        json_encode(
                            [
                                'jsonrpc' => '2.0',
                                'id' => $decoded['id'],
                                'result' => ['marker' => 'prefix'],
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                        '',
                    );

                return (new Response())
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($stream);
            },
        );
        $actual = $this->exchange($http);
        self::assertInstanceOf(McpTransportException::class, $actual['error']);
        self::assertSame(1799990213, $actual['error']->getCode());
        self::assertNull($actual['response']);
    }

    #[Test]
    public function issuedSessionPaginationReconnectAndInstancesDoNotReuseIds(): void
    {
        $http = (new McpTestServer())
            ->willHandshake('session-9')
            ->willReturn(['tools' => [['name' => 'first']], 'nextCursor' => 'next'])
            ->willReturn(['tools' => [['name' => 'second']]])
            ->willHandshake('session-10')
            ->willReturn(['tools' => []])
            ->willHandshake('session-11')
            ->willReturn(['content' => [['type' => 'text', 'text' => 'called']]]);
        $transport = $this->transport($http);
        $client = new McpClient(
            $transport,
            new RecordedContacts(),
            new McpDeadlineFactory(
                new FakeMcpClock(),
                self::createStub(ExtensionConfiguration::class),
            ),
        );
        self::assertSame(
            ['first', 'second'],
            array_column($client->listTools(McpTestServer::server()), 'name'),
        );
        self::assertSame([], $client->listTools(McpTestServer::server()));
        $called = $client->callTool(McpTestServer::server(), 'read_page', ['uid' => 3]);
        self::assertSame('called', $called->text);
        $other = (new McpTestServer())->willReturn([]);
        $this
            ->transport($other)
            ->call(
                McpTestServer::server(),
                'ping',
                [],
                McpOperationDeadline::start(new FakeMcpClock(), 20),
            );
        $ids = [];
        foreach (array_merge($http->received, $other->received) as $request) {
            if ($request['method'] === 'notifications/initialized') {
                self::assertArrayNotHasKey('id', $request['body']);
                continue;
            }

            $id = $request['body']['id'] ?? null;
            self::assertIsString($id);
            self::assertLessThan(100, strlen($id));
            $ids[] = $id;
        }

        self::assertCount(8, $ids);
        self::assertCount(8, array_unique($ids, SORT_STRING));
        self::assertSame(
            [
                'initialize',
                'notifications/initialized',
                'tools/list',
                'tools/list',
                'initialize',
                'notifications/initialized',
                'tools/list',
                'initialize',
                'notifications/initialized',
                'tools/call',
            ],
            $http->methods(),
        );
        self::assertSame('session-9', $http->received[2]['session']);
        self::assertSame('session-10', $http->received[6]['session']);
    }

    #[Test]
    public function exhaustedCounterRefusesBeforeAnotherRequest(): void
    {
        $http = (new McpTestServer())->willReturn([])->willReturn([]);
        $transport = $this->transport($http);
        $transport->call(
            McpTestServer::server(),
            'ping',
            [],
            McpOperationDeadline::start(new FakeMcpClock(), 20),
        );
        $reflection = new ReflectionClass($transport);
        self::assertTrue($reflection->hasProperty('requestSequence'));
        $property = new ReflectionProperty($transport, 'requestSequence');
        $property->setValue($transport, PHP_INT_MAX);

        $caught = null;
        try {
            $transport->call(
                McpTestServer::server(),
                'ping',
                [],
                McpOperationDeadline::start(new FakeMcpClock(), 20),
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(McpTransportException::class, $caught);
        self::assertSame(1799990211, $caught->getCode());
        self::assertCount(1, $http->received);
    }

    #[Test]
    public function aRealChildRotatesInheritedAllocatorStateBeforeSending(): void
    {
        $http = (new McpTestServer())->willReturn([])->willReturn([]);
        $transport = $this->transport($http);
        $transport->call(
            McpTestServer::server(),
            'ping',
            [],
            McpOperationDeadline::start(new FakeMcpClock(), 20),
        );
        $reflection = new ReflectionClass($transport);
        foreach (['requestNamespace', 'requestProcess', 'requestSequence'] as $property) {
            self::assertTrue($reflection->hasProperty($property));
        }

        $state = [];
        foreach (['requestNamespace', 'requestProcess', 'requestSequence'] as $property) {
            $state[$property] = $reflection->getProperty($property)->getValue($transport);
        }

        $script = <<<'PHP_WRAP'
            require getcwd() . '/.Build/vendor/autoload.php';
            class WireChildStubFactory extends \PHPUnit\Framework\TestCase {
                public static function vault(): \Netresearch\NrVault\Service\VaultServiceInterface {
                    return self::createStub(\Netresearch\NrVault\Service\VaultServiceInterface::class);
                }
            }
            $http = (new \Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer())->willReturn([]);
            $transport = new \Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport(
                WireChildStubFactory::vault(),
                new \Netresearch\NrVault\Http\SecureHttpClientFactory(),
                new \TYPO3\CMS\Core\Http\RequestFactory(new \TYPO3\CMS\Core\Http\Client\GuzzleClientFactory()),
                new \TYPO3\CMS\Core\Http\StreamFactory(),
            );
            $transport->setHttpClient($http);
            foreach (json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR) as $name => $value) {
                (new \ReflectionProperty($transport, $name))->setValue($transport, $value);
            }
            $transport->call(
                \Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer::server(), 'ping', [],
                \Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline::start(new \Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock(), 20),
            );
            echo json_encode(['id' => $http->received[0]['body']['id'], 'process' => getmypid()], JSON_THROW_ON_ERROR);
            PHP_WRAP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $script,
                json_encode($state, JSON_THROW_ON_ERROR),
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 5),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 10;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            usleep(10000);
        } while (microtime(true) < $deadline && strlen($stdout) + strlen($stderr) < 16384);

        if ($status['running']) {
            proc_terminate($process, 9);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        self::assertFalse(
            $status['running'],
            'The sole direct PHP child must finish within the watchdog',
        );
        self::assertSame(0, $status['exitcode'], $stderr);
        $child = json_decode($stdout, true);
        self::assertIsArray($child);
        self::assertIsString($child['id'] ?? null);
        self::assertNotSame($state['requestProcess'], $child['process'] ?? null);
        $transport->call(
            McpTestServer::server(),
            'ping',
            [],
            McpOperationDeadline::start(new FakeMcpClock(), 20),
        );
        self::assertNotSame($http->received[0]['body']['id'], $child['id']);
        self::assertNotSame(
            $http->received[1]['body']['id'],
            $child['id'],
            'Inherited sequence alone would collide with the actual next parent request',
        );
    }

    #[Test]
    public function nonResponseEventFramingRemainsIgnored(): void
    {
        $template = "data: not-json\n\ndata: []\n\n" . 'data: {"unrelated":"frame"}' . "\n\n" . 'data: {"jsonrpc":"2.0","id":@ID@,"result":{"marker":"matched"}}' . "\n\n";
        $actual = $this->exchange(
            (new McpTestServer())->willReturnRawForRequest(
                $this->wire($template),
                'text/event-stream',
            ),
        );
        self::assertNull($actual['error']);
        self::assertSame(
            ['marker' => 'matched'],
            $actual['response']['result'] ?? null,
        );
    }

    #[Test]
    public function failedFinalCataloguePageDoesNotRecordSuccessfulContact(): void
    {
        $http = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => [['name' => 'first']], 'nextCursor' => 'last'])
            ->willReturnRawForRequest(
                $this->wire(
                    '{"jsonrpc":"2.0","id":"unrelated","result":{"tools":[]}}',
                ),
            );
        $contacts = new RecordedContacts();
        $client = new McpClient(
            $this->transport($http),
            $contacts,
            new McpDeadlineFactory(
                new FakeMcpClock(),
                self::createStub(ExtensionConfiguration::class),
            ),
        );
        $caught = null;
        try {
            $client->listTools(McpTestServer::server());
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(McpTransportException::class, $caught);
        self::assertSame(1799990213, $caught->getCode());
        self::assertSame([], $contacts->contacts);
        self::assertCount(4, $http->received);
    }

    #[Test]
    public function notificationDiscardsItsBodyWithoutAllocatingARequestId(): void
    {
        $requests = [];
        $http = self::createStub(ClientInterface::class);
        $http
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests): ResponseInterface {
                    $requests[] = (string)$request->getBody();
                    $stream = new Stream('php://temp', 'rw');
                    $stream->write(str_repeat('x', 2 * 1024 * 1024 + 1));
                    $stream->rewind();
                    return (new Response())->withHeader(
                        'Content-Type',
                        'text/event-stream',
                    )->withBody($stream);
                },
            );
        $transport = $this->transport($http);
        $caught = null;
        try {
            $transport->notify(
                McpTestServer::server(),
                'notifications/initialized',
                [],
                McpOperationDeadline::start(new FakeMcpClock(), 20),
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull($caught);
        self::assertCount(1, $requests);
        $body = json_decode($requests[0], true);
        self::assertIsArray($body);
        self::assertSame('notifications/initialized', $body['method']);
        self::assertArrayNotHasKey('id', $body);
        $sequence = (new ReflectionClass($transport))->getProperty('requestSequence');
        self::assertSame(0, $sequence->getValue($transport));
    }
}
