<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpClient::class)]
final class McpClientTest extends AbstractUnitTestCase
{
    private RecordedContacts $health;

    protected function setUp(): void
    {
        parent::setUp();

        $this->health = new RecordedContacts();
    }

    private function clientFor(McpTestServer $fake): McpClient
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return new McpClient($transport, $this->health);
    }

    #[Test]
    public function handshakesBeforeItAsksForAnything(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $fake->methods());
    }

    /**
     * A stateful server issues a session id in the initialize reply and expects
     * it back on every following request.
     */
    #[Test]
    public function carriesTheIssuedSessionThroughTheWholeOperation(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake('sess-7')
            ->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertSame('', $fake->received[0]['session'], 'the handshake itself has no session yet');
        self::assertSame('sess-7', $fake->received[1]['session']);
        self::assertSame('sess-7', $fake->received[2]['session']);
    }

    #[Test]
    public function walksEveryPageOfTheCatalogue(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => [['name' => 'a']], 'nextCursor' => 'p2'])
            ->willReturn(['tools' => [['name' => 'b']], 'nextCursor' => 'p3'])
            ->willReturn(['tools' => [['name' => 'c']]]);

        $tools = $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertSame(['a', 'b', 'c'], array_column($tools, 'name'));
        // 0 = initialize, 1 = the confirmation notification, 2 = the first
        // page, which carries no cursor because there is nothing to resume from.
        self::assertArrayNotHasKey('cursor', $fake->received[2]['body']['params']);
        self::assertSame('p2', $fake->received[3]['body']['params']['cursor']);
        self::assertSame('p3', $fake->received[4]['body']['params']['cursor']);
    }

    /**
     * A cursor that never resolves has to end the walk. Left alone it is an
     * unbounded loop driven by a third party.
     */
    #[Test]
    public function givesUpOnACursorThatNeverEnds(): void
    {
        $fake = (new McpTestServer())->willHandshake();
        for ($i = 0; $i < 60; ++$i) {
            $fake->willReturn(['tools' => [], 'nextCursor' => 'forever']);
        }

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('did not end within 50 pages');

        $this->clientFor($fake)->listTools(McpTestServer::server());
    }

    #[Test]
    public function dropsAMalformedEntryRatherThanTheWholeCatalogue(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => [['name' => 'good'], 'not an object', ['name' => 'also good']]]);

        $tools = $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertSame(['good', 'also good'], array_column($tools, 'name'));
    }

    #[Test]
    public function refusesAListingWithoutAToolsArray(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['something else' => true]);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('carries no "tools" array');

        $this->clientFor($fake)->listTools(McpTestServer::server());
    }

    #[Test]
    public function joinsTheTextBlocksOfAToolResult(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'image', 'data' => 'ignored'],
                ['type' => 'text', 'text' => 'second'],
            ]]);

        $answer = $this->clientFor($fake)->callTool(McpTestServer::server(), 'do_it', ['a' => 1]);

        self::assertSame("first\nsecond", $answer);
        self::assertSame('do_it', $fake->received[2]['body']['params']['name']);
        self::assertSame(['a' => 1], $fake->received[2]['body']['params']['arguments']);
    }

    /**
     * A tool-level failure is a result, not a transport fault: the model should
     * see what went wrong and may reasonably do something else.
     */
    #[Test]
    public function reportsAToolLevelErrorAsTextInsteadOfThrowing(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn([
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'the file does not exist']],
            ]);

        $answer = $this->clientFor($fake)->callTool(McpTestServer::server(), 'read_file', []);

        self::assertStringContainsString('reported an error', $answer);
        self::assertStringContainsString('the file does not exist', $answer);
    }

    /**
     * An empty string would read as an empty file rather than as no answer.
     */
    #[Test]
    public function saysSoWhenAToolReturnsNothingReadable(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => [['type' => 'image', 'data' => 'x']]]);

        $answer = $this->clientFor($fake)->callTool(McpTestServer::server(), 'render', []);

        self::assertSame('The remote tool returned no textual content.', $answer);
    }

    #[Test]
    public function sendsEmptyArgumentsAsAnObject(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => []]);

        $this->clientFor($fake)->callTool(McpTestServer::server(), 'no_args', []);

        self::assertStringContainsString('"arguments":{}', $fake->received[2]['raw']);
    }

    /**
     * Declaring a capability invites the server to use it, and this client
     * implements none of them.
     */
    #[Test]
    public function declaresNoCapabilitiesInTheHandshake(): void
    {
        $fake = (new McpTestServer())->willHandshake()->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertStringContainsString('"capabilities":{}', $fake->received[0]['raw']);
    }

    /**
     * The connection test is the handshake and nothing after it: no listing,
     * no call, nothing that could rewrite a catalogue (ADR-154).
     */
    #[Test]
    public function theConnectionTestPerformsTheHandshakeAndStops(): void
    {
        $fake = (new McpTestServer())->willReturn([
            'protocolVersion' => '2025-03-26',
            'capabilities'    => [],
            'serverInfo'      => ['name' => 'Example MCP', 'version' => '4.2'],
        ]);

        $report = $this->clientFor($fake)->ping(McpTestServer::server());

        self::assertSame(['initialize', 'notifications/initialized'], $fake->methods());
        self::assertTrue($report->reachable);
        self::assertSame('', $report->error);
        // The version the SERVER chose, not the one this client asked for.
        self::assertSame('2025-03-26', $report->protocolVersion);
        self::assertSame('Example MCP', $report->serverName);
        self::assertSame('4.2', $report->serverVersion);
    }

    /**
     * The latency has to be a measurement.
     *
     * Every other assertion in this class is satisfied by a transport that
     * returns a constant — `durationMs => 0` passes them all. This one makes
     * the server slow and requires the number to notice, end to end: what
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport} timed is what
     * the report shows and what the recorder is handed.
     */
    #[Test]
    public function theReportedLatencyIsTheMeasuredOne(): void
    {
        $fake = (new McpTestServer())
            ->willTakeAtLeast(60)
            ->willReturn(['protocolVersion' => '2025-06-18']);

        $report = $this->clientFor($fake)->ping(McpTestServer::server());

        self::assertGreaterThanOrEqual(50, $report->latencyMs, 'a handshake that took 60 ms is not reported as faster');
        self::assertLessThan(5_000, $report->latencyMs, 'nor as a number nobody measured');
        self::assertSame(
            $report->latencyMs,
            $this->health->contacts[0]['latencyMs'],
            'the recorder is handed the same measurement the operator is shown',
        );
    }

    /**
     * The self-description is remote text shown in a backend view.
     */
    #[Test]
    public function clipsWhatTheServerSaysAboutItself(): void
    {
        $fake = (new McpTestServer())->willReturn([
            'protocolVersion' => '2025-06-18',
            'serverInfo'      => [
                'name'    => str_repeat('a', 500),
                'version' => "1.0\nX-Injected: yes",
            ],
        ]);

        $report = $this->clientFor($fake)->ping(McpTestServer::server());

        self::assertSame(101, mb_strlen($report->serverName), 'clipped to the limit plus the ellipsis');
        self::assertSame('1.0 X-Injected: yes', $report->serverVersion, 'control characters are flattened');
    }

    /**
     * A server that answers nothing usable is a finding, not an exception: the
     * operator asked whether it is alive and the answer is no.
     */
    #[Test]
    public function reportsAnUnreachableServerInsteadOfThrowing(): void
    {
        $fake = (new McpTestServer())->willReturnRaw('', 503);

        $report = $this->clientFor($fake)->ping(McpTestServer::server());

        self::assertFalse($report->reachable);
        self::assertStringContainsString('503', $report->error);
        self::assertSame(0, $report->latencyMs);
        self::assertSame([], $this->health->contacts, 'a server that did not answer was not contacted');
    }

    #[Test]
    public function refusesToProbeAServerWithoutAUrl(): void
    {
        $fake = new McpTestServer();

        $report = $this->clientFor($fake)->ping(new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'srv',
            name: 'No endpoint',
            description: '',
            url: '',
            authCredential: '',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: 'publicContent',
            requiresApproval: '1',
            enabled: true,
            importStatus: 'never_imported',
            importError: '',
            lastImported: 0,
            toolCount: 0,
            lastContact: 0,
            lastLatencyMs: 0,
            tstamp: 0,
            crdate: 0,
        ));

        self::assertFalse($report->reachable);
        self::assertSame('The server has no URL.', $report->error);
        self::assertSame([], $fake->received, 'nothing was sent');
    }

    /**
     * Once per operation, not once per round trip: a catalogue walk is one
     * contact however many pages it took (ADR-154).
     */
    #[Test]
    public function recordsOneContactPerCompletedOperation(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => [], 'nextCursor' => 'p2'])
            ->willReturn(['tools' => []]);

        $this->clientFor($fake)->listTools(McpTestServer::server());

        self::assertCount(1, $this->health->contacts);
        self::assertSame('srv', $this->health->contacts[0]['identifier']);
    }

    /**
     * A tool-level failure still proves the server is there. The distinction
     * matters: an operator debugging a failing tool should not also be told
     * the server is unreachable.
     */
    #[Test]
    public function aToolLevelErrorStillCountsAsContact(): void
    {
        $fake = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['isError' => true, 'content' => [['type' => 'text', 'text' => 'nope']]]);

        $this->clientFor($fake)->callTool(McpTestServer::server(), 'do_it', []);

        self::assertCount(1, $this->health->contacts);
    }

    /**
     * The catalogue walk that never finishes throws, and the throw must not be
     * preceded by a contact: the operation did not succeed.
     */
    #[Test]
    public function recordsNothingWhenTheOperationFails(): void
    {
        $fake = (new McpTestServer())->willHandshake()->willReturn(['not a listing' => true]);

        try {
            $this->clientFor($fake)->listTools(McpTestServer::server());
            self::fail('the malformed listing should have thrown');
        } catch (McpTransportException) {
            // expected — the assertion below is the point.
        }

        self::assertSame([], $this->health->contacts);
    }
}
