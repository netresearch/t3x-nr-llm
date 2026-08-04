<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A scripted MCP server for the tests.
 *
 * Doubles the HTTP client, not the transport or the client: both of those are
 * final, and giving either an interface purely so a test could replace it would
 * add a seam nothing in production needs. Faking at the bottom also means the
 * tests exercise the real JSON-RPC encoding, the real status handling and the
 * real handshake ordering rather than a description of them.
 */
final class McpTestServer implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $queued = [];

    /**
     * The decoded body is convenient; the raw one is the only honest record of
     * what went on the wire — decoding turns a JSON `{}` into a PHP `[]`, which
     * re-encodes as `[]` and would hide the very distinction a strict server
     * cares about.
     *
     * @var list<array{method: string|null, body: array<string, mixed>, raw: string, session: string}>
     */
    public array $received = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $raw     = (string)$request->getBody();
        $decoded = json_decode($raw, true);
        $body    = is_array($decoded) ? $decoded : [];

        /** @var array<string, mixed> $body */
        $method = $body['method'] ?? null;

        $this->received[] = [
            'method'  => is_string($method) ? $method : null,
            'body'    => $body,
            'raw'     => $raw,
            'session' => $request->getHeaderLine('Mcp-Session-Id'),
        ];

        // A notification carries no id and gets no reply, so it must not eat a
        // queued response — otherwise every test would have to pad its queue
        // with a placeholder for the handshake confirmation.
        if (!isset($body['id']) || $this->queued === []) {
            return (new Response())->withStatus(202);
        }

        return array_shift($this->queued);
    }

    /**
     * Queue a JSON-RPC result object.
     *
     * @param array<string, mixed> $result
     */
    public function willReturn(array $result, ?string $sessionId = null): self
    {
        return $this->willReturnRaw((string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]), 200, 'application/json', $sessionId);
    }

    /**
     * Queue a JSON-RPC error object.
     */
    public function willReturnRpcError(int $code, string $message): self
    {
        return $this->willReturnRaw(
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => $code, 'message' => $message]]),
            200,
            'application/json',
        );
    }

    public function willReturnRaw(string $body, int $status = 200, string $contentType = 'application/json', ?string $sessionId = null): self
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $response = (new Response())
            ->withStatus($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($stream);

        if ($sessionId !== null) {
            $response = $response->withHeader('Mcp-Session-Id', $sessionId);
        }

        $this->queued[] = $response;

        return $this;
    }

    /**
     * Queue the handshake pair every operation opens with.
     */
    public function willHandshake(?string $sessionId = null): self
    {
        return $this->willReturn(['protocolVersion' => '2025-06-18', 'capabilities' => []], $sessionId);
    }

    /**
     * The method names the server saw, in order.
     *
     * @return list<string|null>
     */
    public function methods(): array
    {
        return array_map(static fn(array $call): ?string => $call['method'], $this->received);
    }

    public static function server(string $identifier = 'srv', string $dataClass = 'publicContent'): McpServerRecord
    {
        return new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: $identifier,
            name: 'Test server',
            description: '',
            url: 'https://mcp.example.com/rpc',
            authCredential: '',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: $dataClass,
            enabled: true,
            importStatus: 'never_imported',
            importError: '',
            lastImported: 0,
            toolCount: 0,
            tstamp: 0,
            crdate: 0,
        );
    }
}
