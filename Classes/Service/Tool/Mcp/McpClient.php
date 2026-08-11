<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpCallOutcome;
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

    /**
     * How long a dropped content block's type may be when it is named back to
     * the caller. The type is a string the far side chose, so it is clipped and
     * stripped like every other remote label.
     */
    private const BLOCK_TYPE_LIMIT = 32;

    /**
     * How many DISTINCT dropped block types the note lists before it stops.
     * A hostile server can invent a new type per block; the count stays exact,
     * the enumeration does not grow with it.
     */
    private const MAX_REPORTED_BLOCK_TYPES = 5;

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
     * Invoke one remote tool and return what it answered.
     *
     * MCP lets a server answer with a list of typed content blocks. Only text
     * blocks are read: an image or an embedded resource has no representation
     * in a tool result here, and inventing a rendering the caller never asked
     * for would be worse than not carrying it.
     *
     * Dropping one is NOT silent, though (ADR-161). The answer LEADS with how
     * many blocks were dropped and of which types, because the alternative is a
     * model that reads a partial answer as the whole one — and, when every
     * block was non-text, is told the tool "returned no textual content" about
     * a call that in fact returned an image. It leads rather than trails
     * because the result is byte-bounded before it reaches the model, and a
     * note at the end is the first thing a cut removes — on the long answers
     * that get cut, which is where being told the answer is partial matters
     * most. The note is our sentence, not the server's: the types are stripped,
     * clipped and enumerated only up to a bound, so a hostile server cannot
     * write the tool result through it.
     *
     * The outcome is typed rather than a string because the protocol's own
     * `isError` decides what KIND of step the run records; see
     * {@see McpCallOutcome}.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws McpTransportException
     */
    public function callTool(McpServerRecord $server, string $remoteName, array $arguments): McpCallOutcome
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

        $flattened = $this->flattenContent($result['content'] ?? null);
        $text      = $flattened['text'];
        $note      = $this->omissionNote($flattened['omitted']);

        // `isError` is the protocol's way of reporting a tool-level failure
        // inside a successful JSON-RPC response. It is a result, not a
        // transport fault: the model should see what went wrong and may
        // reasonably try something else, so it is returned rather than thrown —
        // but it is returned AS a failure, because the flag is what the
        // persisted step carries and what a reader counts (ADR-161).
        if (($result['isError'] ?? false) === true) {
            return new McpCallOutcome(
                $note . 'The remote tool reported an error: ' . ($text === '' ? 'no detail given.' : $text),
                true,
            );
        }

        return new McpCallOutcome(
            $note . ($text === '' ? 'The remote tool returned no textual content.' : $text),
            false,
        );
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
     * Flatten the protocol's content blocks into plain text, counting what had
     * to be left behind.
     *
     * A text block whose `text` is missing or empty is NOT counted as dropped:
     * it is a text block that said nothing, and reporting it would tell the
     * caller content was withheld when none was.
     *
     * @return array{text: string, omitted: array<string, int>} the readable
     *                                                          text, and how many blocks of each non-text type were dropped
     */
    private function flattenContent(mixed $content): array
    {
        if (!\is_array($content)) {
            return ['text' => '', 'omitted' => []];
        }

        $parts   = [];
        $omitted = [];

        foreach ($content as $block) {
            $type = \is_array($block) ? ($block['type'] ?? null) : null;

            if ($type === 'text') {
                /** @var array<string, mixed> $block */
                $text = $block['text'] ?? null;
                if (\is_string($text) && $text !== '') {
                    $parts[] = $text;
                }

                continue;
            }

            $label           = $this->blockTypeLabel($type);
            $omitted[$label] = ($omitted[$label] ?? 0) + 1;
        }

        return ['text' => implode("\n", $parts), 'omitted' => $omitted];
    }

    /**
     * The sentence the answer LEADS with when it carried blocks this client
     * cannot read, or '' when it carried none.
     *
     * It is a prefix, not a suffix, because the tool result is truncated to a
     * byte bound before the model sees it
     * ({@see \Netresearch\NrLlm\Service\Tool\ToolResultBounder}), and a
     * trailing note is dropped by exactly the cut that makes the answer
     * partial.
     *
     * @param array<string, int> $omitted
     */
    private function omissionNote(array $omitted): string
    {
        if ($omitted === []) {
            return '';
        }

        $total = array_sum($omitted);

        // Sorted so the same answer always produces the same note, whatever
        // order the server happened to send its blocks in.
        ksort($omitted);
        $types    = array_keys($omitted);
        $listed   = \array_slice($types, 0, self::MAX_REPORTED_BLOCK_TYPES);
        $ellipsis = \count($types) > self::MAX_REPORTED_BLOCK_TYPES ? ', …' : '';

        return sprintf(
            "[nr_llm reads text only and dropped %d non-text content %s (%s%s).]\n",
            $total,
            $total === 1 ? 'block' : 'blocks',
            implode(', ', $listed),
            $ellipsis,
        );
    }

    /**
     * A dropped block's type, reduced to something safe to repeat: anything
     * outside the identifier character set is removed rather than escaped, so
     * the note cannot be steered by what the server called its block.
     */
    private function blockTypeLabel(mixed $type): string
    {
        if (!\is_string($type)) {
            return 'unknown';
        }

        $clean = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $type);

        return $clean === '' ? 'unknown' : mb_substr($clean, 0, self::BLOCK_TYPE_LIMIT);
    }
}
