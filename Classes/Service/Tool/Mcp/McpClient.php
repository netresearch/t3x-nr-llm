<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolsPage;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use stdClass;

/**
 * The MCP conversation: handshake, catalogue listing, tool invocation (ADR-116).
 *
 * Owns the protocol; {@see McpHttpTransport} owns the wire. The split exists so
 * the handshake and the pagination loop can be asserted against a fake
 * transport, without a PSR-18 double and without a live server.
 *
 * A session is per OPERATION, not per instance. Each public method opens one,
 * uses it and drops it. That costs two extra round trips per import and per
 * tool call — the handshake and its confirmation — and buys the property that
 * matters: this class is a DI singleton on a long-lived queue worker, so a
 * retained session id would be handed to whatever server came next, and a
 * session belonging to server A is meaningless, at best, when presented to
 * server B. The price is paid per call and is bounded by the transport
 * timeout; the alternative is a cache whose invalidation nobody owns.
 */
final readonly class McpClient
{
    /**
     * The revision this client speaks. Sent in the handshake; a server that
     * cannot serve it answers with its own and we proceed, because the two
     * calls this client makes have been stable across revisions.
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * Pages a catalogue listing will walk before giving up.
     *
     * A cursor that never resolves to null is a server bug or a hostile server;
     * either way the loop has to end. At the page sizes MCP servers use this is
     * far more tools than a catalogue an operator would want.
     */
    private const MAX_PAGES = 50;

    public function __construct(
        private McpHttpTransport $transport,
    ) {}

    /**
     * Every tool the server advertises, across all pages.
     *
     *
     * @throws McpTransportException
     *
     * @return list<array<string, mixed>> the advertised tool objects, verbatim
     */
    public function listTools(McpServerRecord $server): array
    {
        $session = $this->openSession($server);

        $tools  = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $result = $this->transport->call(
                $server,
                'tools/list',
                $cursor === null ? [] : ['cursor' => $cursor],
                $session,
            );

            $parsed = $this->parseToolsPage($server, $result['result']);
            foreach ($parsed->tools as $tool) {
                $tools[] = $tool;
            }

            $cursor = $parsed->nextCursor;
            if ($cursor === null) {
                return $tools;
            }
        }

        throw McpTransportException::forMalformedResponse(
            $server->identifier,
            sprintf('the tool listing did not end within %d pages', self::MAX_PAGES),
        );
    }

    /**
     * Invoke one remote tool and return its result as text.
     *
     * MCP lets a server answer with a list of typed content blocks. Only text
     * blocks are read: an image or an embedded resource has no representation
     * in a tool result here, and silently dropping one is better than inventing
     * a rendering the caller never asked for. A server that returns nothing
     * readable yields an explicit note rather than an empty string, so the
     * model is told the call produced no text instead of inferring failure.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws McpTransportException
     */
    public function callTool(McpServerRecord $server, string $remoteName, array $arguments): string
    {
        $session = $this->openSession($server);

        $result = $this->transport->call($server, 'tools/call', [
            'name'      => $remoteName,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ], $session)['result'];

        $text = $this->textFromContent($result['content'] ?? null);

        // `isError` is the protocol's way of reporting a tool-level failure
        // inside a successful JSON-RPC response. It is a result, not a
        // transport fault: the model should see what went wrong and may
        // reasonably try something else, so it is returned rather than thrown.
        if (($result['isError'] ?? false) === true) {
            return 'The remote tool reported an error: ' . ($text === '' ? 'no detail given.' : $text);
        }

        return $text === '' ? 'The remote tool returned no textual content.' : $text;
    }

    /**
     * Perform the initialize handshake and return the session id, if the server
     * issued one.
     *
     * A server that runs statelessly issues none; null then simply means no
     * session header on the following request, which is valid.
     *
     * @throws McpTransportException
     */
    private function openSession(McpServerRecord $server): ?string
    {
        $session = $this->transport->call($server, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            // No capabilities are declared because none are offered: this
            // client does not accept sampling requests, does not expose roots
            // and does not subscribe to notifications. Declaring a capability
            // we do not implement invites the server to use it.
            'capabilities'    => new stdClass(),
            'clientInfo'      => [
                'name'    => 'nr_llm',
                'version' => '1',
            ],
        ])['sessionId'];

        // The protocol requires the client to confirm it is ready before it
        // issues requests. A server may reject everything until it arrives.
        $this->transport->notify($server, 'notifications/initialized', [], $session);

        return $session;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @throws McpTransportException
     */
    private function parseToolsPage(McpServerRecord $server, array $result): McpToolsPage
    {
        $tools = $result['tools'] ?? null;
        if (!\is_array($tools)) {
            throw McpTransportException::forMalformedResponse($server->identifier, 'the tool listing carries no "tools" array');
        }

        $objects = [];
        foreach ($tools as $tool) {
            // A non-object entry is dropped here rather than rejected: one
            // malformed entry must not cost the operator the whole catalogue.
            // The import counts what it could not name, so nothing vanishes
            // silently.
            if (\is_array($tool)) {
                /** @var array<string, mixed> $tool */
                $objects[] = $tool;
            }
        }

        $cursor = $result['nextCursor'] ?? null;

        return new McpToolsPage(
            $objects,
            \is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
    }

    /**
     * Flatten the protocol's content blocks into plain text.
     */
    private function textFromContent(mixed $content): string
    {
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) !== 'text') {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && $text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }
}
