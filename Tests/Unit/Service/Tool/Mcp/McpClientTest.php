<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
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
    private function clientFor(McpTestServer $fake): McpClient
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($fake);

        return new McpClient($transport);
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
}
