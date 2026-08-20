<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
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

    /**
     * A budget with room in it, so the checks below are about the wire rather
     * than about the deadline (ADR-170).
     */
    private function deadline(int $totalSeconds = 20): McpOperationDeadline
    {
        return McpOperationDeadline::start(new FakeMcpClock(), $totalSeconds);
    }

    #[Test]
    public function sendsJsonRpcAndReturnsTheResultObject(): void
    {
        $fake = (new McpTestServer())->willReturn(['tools' => []]);

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'tools/list', ['cursor' => 'abc'], $this->deadline());

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

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());

        self::assertStringContainsString('"params":{}', $fake->received[0]['raw']);
    }

    #[Test]
    public function carriesTheSessionHeaderWhenOneIsGiven(): void
    {
        $fake = (new McpTestServer())->willReturn([]);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline(), 'sess-1');

        self::assertSame('sess-1', $fake->received[0]['session']);
    }

    #[Test]
    public function returnsTheSessionIdTheServerIssued(): void
    {
        $fake = (new McpTestServer())->willReturn([], 'sess-9');

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'initialize', [], $this->deadline());

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

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    #[Test]
    public function turnsAJsonRpcErrorIntoATypedException(): void
    {
        $fake = (new McpTestServer())->willReturnRpcError(-32601, 'Method not found');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990214);
        $this->expectExceptionMessage('Method not found');

        $this->transportFor($fake)->call(McpTestServer::server(), 'nope', [], $this->deadline());
    }

    #[Test]
    public function offersBothMediaTypesTheStreamableTransportRequires(): void
    {
        // The reference servers answer `application/json` alone with 406, so
        // the header is part of the contract, not a preference (ADR-181).
        $fake = (new McpTestServer())->willReturn(['tools' => []]);

        $this->transportFor($fake)->call(McpTestServer::server(), 'tools/list', [], $this->deadline());

        self::assertSame('application/json, text/event-stream', $fake->received[0]['accept']);
    }

    #[Test]
    public function unframesAnEventStreamThatCarriesTheResponse(): void
    {
        $fake = (new McpTestServer())->willReturnRaw(
            "event: message\r\nid: 7\r\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\r\ndata:  \"result\":{\"tools\":[{\"name\":\"x\"}]}}\r\n\r\n",
            200,
            'text/event-stream; charset=utf-8',
        );

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'tools/list', [], $this->deadline());

        // Two `data:` lines of one event join with a newline; `event:` and
        // `id:` lines and the leading space after `data:` are framing, not
        // payload; CRLF is a line ending like any other.
        self::assertSame(['tools' => [['name' => 'x']]], $answer['result']);
    }

    #[Test]
    public function passesOverAServerNotificationOnTheStreamAndTakesTheResponse(): void
    {
        $fake = (new McpTestServer())->willReturnRaw(
            ": keep-alive\n\n"
            . "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\",\"params\":{\"level\":\"info\"}}\n\n"
            . "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n",
            200,
            'text/event-stream',
        );

        $answer = $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());

        self::assertSame(['ok' => true], $answer['result']);
    }

    #[Test]
    public function anEventStreamWithoutAResponseIsAMalformedAnswer(): void
    {
        $fake = (new McpTestServer())->willReturnRaw(
            "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\",\"params\":{}}\n\n",
            200,
            'text/event-stream',
        );

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessageMatches('/carried no response/');

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    #[Test]
    public function anEmptyEventStreamIsAMalformedAnswer(): void
    {
        $fake = (new McpTestServer())->willReturnRaw(": ping\n\n", 200, 'text/event-stream');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessageMatches('/carried no message/');

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    #[Test]
    public function stillRefusesAContentTypeItCannotRead(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('<html>maintenance</html>', 200, 'text/html');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990215);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    #[Test]
    public function rejectsABodyThatIsNotJson(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('<html>maintenance</html>');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990213);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    #[Test]
    public function rejectsAResponseWithNeitherResultNorError(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('{"jsonrpc":"2.0","id":1}');

        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990213);

        $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
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
            $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
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
            $this->transportFor($fake)->call(McpTestServer::server(), 'ping', [], $this->deadline());
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

        $transport->call(McpTestServer::server(), 'ping', [], $this->deadline());
    }

    /**
     * A notification has no id and its reply is not parsed — a 202 with an
     * empty body is the normal answer and must not be read as a fault.
     */
    #[Test]
    public function sendsANotificationWithoutAnIdAndIgnoresTheReply(): void
    {
        $fake = new McpTestServer();

        $this->transportFor($fake)->notify(McpTestServer::server(), 'notifications/initialized', [], $this->deadline());

        self::assertSame('notifications/initialized', $fake->received[0]['method']);
        self::assertArrayNotHasKey('id', $fake->received[0]['body']);
    }

    /**
     * A spent budget stops the request before it is built, on BOTH transport
     * entry points (ADR-170). Checking it inside the client builder instead
     * would leave the seam these tests run through unguarded — and would put
     * the throw inside the catch that reports a failing far side, so an
     * exhausted budget would come out as "the server could not be reached".
     */
    #[Test]
    public function refusesToSendAnythingOnceTheBudgetIsSpent(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = McpOperationDeadline::start($clock, 20);
        $clock->advanceSeconds(20.0);

        $fake      = (new McpTestServer())->willReturn(['tools' => []]);
        $transport = $this->transportFor($fake);

        try {
            $transport->call(McpTestServer::server(), 'tools/list', [], $deadline);
            self::fail('call() should have refused a spent budget');
        } catch (McpTransportException $e) {
            self::assertStringContainsString('20-second operation budget', $e->getMessage());
            self::assertSame(1799990217, $e->getCode(), 'a kind of its own, not a generic transport failure');
        }

        try {
            $transport->notify(McpTestServer::server(), 'notifications/initialized', [], $deadline);
            self::fail('notify() should have refused a spent budget');
        } catch (McpTransportException $e) {
            self::assertSame(1799990217, $e->getCode(), 'the notification leg is bounded by the same budget');
        }

        self::assertSame([], $fake->received, 'nothing went on the wire');
    }
}
