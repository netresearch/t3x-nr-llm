<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpHttpTransport::class)]
final class McpHttpTransportTest extends AbstractUnitTestCase
{
    private function transportFor(McpTestServer $fake): McpHttpTransport
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return $transport;
    }

    #[Test]
    public function sendsJsonRpcAndReturnsTheResultObject(): void
    {
        $fake = (new McpTestServer())->willReturn(['tools' => []]);

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'tools/list', ['cursor' => 'abc']);

        self::assertSame(['tools' => []], $answer['result']);
        self::assertSame('tools/list', $fake->received[0]['method']);
        self::assertSame('2.0', $fake->received[0]['body']['jsonrpc']);
        self::assertSame(['cursor' => 'abc'], $fake->received[0]['body']['params']);
    }

    /**
     * An empty parameter set has to go on the wire as a JSON object. Encoded
     * from an empty PHP array it would become `[]`, which is a different type
     * and which strict servers reject.
     */
    #[Test]
    public function sendsEmptyParametersAsAnObject(): void
    {
        $fake = (new McpTestServer())->willReturn([]);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);

        self::assertStringContainsString('"params":{}', $fake->received[0]['raw']);
    }

    #[Test]
    public function carriesTheSessionHeaderWhenOneIsGiven(): void
    {
        $fake = (new McpTestServer())->willReturn([]);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], 'sess-1');

        self::assertSame('sess-1', $fake->received[0]['session']);
    }

    #[Test]
    public function returnsTheSessionIdTheServerIssued(): void
    {
        $fake = (new McpTestServer())->willReturn([], 'sess-9');

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'initialize', []);

        self::assertSame('sess-9', $answer['sessionId']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingStatuses(): array
    {
        return [
            'redirect'     => [302],
            'unauthorised' => [401],
            'not found'    => [404],
            'server error' => [500],
        ];
    }

    /**
     * 3xx is in the list on purpose: following a redirect would carry the
     * credential to a host the SSRF gate never saw.
     */
    #[Test]
    #[DataProvider('failingStatuses')]
    public function rejectsAnyStatusFromThreeHundredUp(int $status): void
    {
        $fake = (new McpTestServer())->willReturnRaw('{}', $status);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990212);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
    }

    #[Test]
    public function turnsAJsonRpcErrorIntoATypedException(): void
    {
        $fake = (new McpTestServer())->willReturnRpcError(-32601, 'Method not found');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990214);
        $this->expectExceptionMessage('Method not found');

        $this->transportFor($fake)->call(McpTestServer::server(), 'nope', []);
    }

    #[Test]
    public function refusesAnEventStreamRatherThanGuessingAtIt(): void
    {
        $fake = (new McpTestServer())->willReturnRaw("data: {}\n\n", 200, 'text/event-stream');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990215);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
    }

    #[Test]
    public function rejectsABodyThatIsNotJson(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('<html>maintenance</html>');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990213);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
    }

    #[Test]
    public function rejectsAResponseWithNeitherResultNorError(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('{"jsonrpc":"2.0","id":1}');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990213);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
    }

    /**
     * A server's own text reaches a log line and a backend view, so it must not
     * be able to carry a newline that forges a second record.
     */
    #[Test]
    public function stripsControlCharactersOutOfRemoteText(): void
    {
        $fake = (new McpTestServer())->willReturnRpcError(-1, "first line\nsecond line");

        try {
            $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
            self::fail('expected the call to be refused');
        } catch (McpTransportException $e) {
            self::assertStringNotContainsString("\n", $e->getMessage());
            self::assertStringContainsString('first line second line', $e->getMessage());
        }
    }

    #[Test]
    public function boundsHowMuchRemoteTextItRepeats(): void
    {
        $fake = (new McpTestServer())->willReturnRpcError(-1, str_repeat('x', 5000));

        try {
            $this->transportFor($fake)->call(McpTestServer::server(), 'ping', []);
            self::fail('expected the call to be refused');
        } catch (McpTransportException $e) {
            self::assertLessThan(500, mb_strlen($e->getMessage()));
        }
    }

    #[Test]
    public function reportsATransportFailureAgainstTheServerItBelongsTo(): void
    {
        $throwing = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('connection refused', 1799990230);
            }
        };

        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($throwing);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990211);
        $this->expectExceptionMessage('"srv"');

        $transport->call(McpTestServer::server(), 'ping', []);
    }

    /**
     * A notification has no id and its reply is not parsed — a 202 with an
     * empty body is the normal answer and must not be read as a fault.
     */
    #[Test]
    public function sendsANotificationWithoutAnIdAndIgnoresTheReply(): void
    {
        $fake = new McpTestServer();

        $this->transportFor($fake)->notify(McpTestServer::server(), 'notifications/initialized', []);

        self::assertSame('notifications/initialized', $fake->received[0]['method']);
        self::assertArrayNotHasKey('id', $fake->received[0]['body']);
    }
}
