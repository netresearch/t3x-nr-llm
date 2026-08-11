<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpConnectionReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolsPage;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use stdClass;
use Throwable;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

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
 *
 * It is also where liveness is recorded (ADR-154). An operation is the unit an
 * operator reasons about — "the server answered" — and it is the only level at
 * which one write covers several round trips.
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

    /**
     * How much of a server's self-description the connection test keeps. It is
     * remote text shown in a backend view; enough to recognise the product,
     * short enough that a hostile server cannot fill the page.
     */
    private const REMOTE_LABEL_LIMIT = 100;

    public function __construct(
        private McpHttpTransport $transport,
        private McpHealthRecorderInterface $health,
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
        $session = $this->openSession($server)['sessionId'];

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
                // Recorded once the walk completed, with the latency of the
                // page that ended it. A partial walk that then throws records
                // nothing: the operation did not succeed, and half a catalogue
                // is not a contact worth reporting as one.
                $this->health->recordContact($server, $result['durationMs']);

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
        $session = $this->openSession($server)['sessionId'];

        $answer = $this->transport->call($server, 'tools/call', [
            'name'      => $remoteName,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ], $session);

        // The server answered, so it is alive — including when the answer is a
        // tool-level `isError` below. That is the tool failing, not the server.
        $this->health->recordContact($server, $answer['durationMs']);

        $result = $answer['result'];

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
     * Ask whether the server is there, and report what it said (ADR-154).
     *
     * The handshake and nothing else: no catalogue is fetched, no row of
     * `tx_nrllm_mcp_tool` is written, no import status is touched. An operator
     * checking a server must be able to do it without also rewriting what its
     * tools are — and without the check being the thing that orphans a tool.
     *
     * A success is a contact like any other and is recorded. A failure is
     * returned, not stored: `import_error` describes the last import, and a
     * connection test that overwrote it would erase the reason an import
     * failed with the reason a probe failed.
     */
    public function ping(McpServerRecord $server): McpConnectionReport
    {
        if ($server->url === '') {
            return McpConnectionReport::unreachable($this->noUrlMessage());
        }

        try {
            $handshake = $this->openSession($server);
        } catch (McpTransportException $e) {
            return McpConnectionReport::unreachable($e->getMessage());
        }

        $this->health->recordContact($server, $handshake['durationMs']);

        $result = $handshake['result'];
        $info   = $result['serverInfo'] ?? null;
        $info   = \is_array($info) ? $info : [];

        return McpConnectionReport::reached(
            $handshake['durationMs'],
            // The version the server chose, which may differ from the one this
            // client asked for; that difference is exactly what an operator
            // running a connection test wants to see.
            $this->remoteLabel($result['protocolVersion'] ?? null),
            $this->remoteLabel($info['name'] ?? null),
            $this->remoteLabel($info['version'] ?? null),
        );
    }

    /**
     * Perform the initialize handshake.
     *
     * Returns the whole exchange rather than only the session id, because
     * {@see self::ping()} reports what the server said about itself and
     * duplicating the handshake for that would be a second implementation of
     * the one sequence the protocol prescribes.
     *
     * A server that runs statelessly issues no session; a null `sessionId`
     * then simply means no session header on the following request, which is
     * valid.
     *
     * @throws McpTransportException
     *
     * @return array{result: array<string, mixed>, sessionId: string|null, durationMs: int}
     */
    private function openSession(McpServerRecord $server): array
    {
        $handshake = $this->transport->call($server, 'initialize', [
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
        ]);

        // The protocol requires the client to confirm it is ready before it
        // issues requests. A server may reject everything until it arrives.
        $this->transport->notify($server, 'notifications/initialized', [], $handshake['sessionId']);

        return $handshake;
    }

    /**
     * The one operator-facing sentence this class writes itself.
     *
     * Every other reason a probe fails comes out of
     * {@see McpTransportException} and quotes the far side, so it stays in the
     * language the far side spoke. This one is our own statement about a local
     * misconfiguration and is translated like the module's other labels.
     * Defensively, because outside a full TYPO3 request there is no language
     * service and the operator still needs a sentence.
     */
    private function noUrlMessage(): string
    {
        $fallback = 'The server has no URL.';

        try {
            return LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.mcp.noUrl',
                'NrLlm',
            ) ?? $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * A short, control-character-free rendering of something a remote party
     * wrote about itself, or '' for anything that is not a string.
     */
    private function remoteLabel(mixed $value): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $clean = trim(preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? '');

        return mb_strlen($clean) <= self::REMOTE_LABEL_LIMIT
            ? $clean
            : mb_substr($clean, 0, self::REMOTE_LABEL_LIMIT) . '…';
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
