<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use JsonException;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Throwable;

/**
 * One JSON-RPC round trip to one MCP server over HTTP (ADR-116).
 *
 * Deliberately stateless and dumb: it builds a request, sends it through the
 * SSRF-guarded vault client and hands back the `result` object. Sessions, the
 * initialize handshake and pagination live in {@see McpClient}, so this class
 * stays assertable against a fake PSR-18 client.
 *
 * Two properties are load-bearing and neither is an accident:
 *
 * - **The client is built per server, per call.** It is never memoised on the
 *   instance. This class is a DI singleton, so a cached authenticated client
 *   would send the first server's token to the second — a credential leak
 *   between two operator-configured third parties, which is exactly the kind
 *   of mistake that never shows up in a test that uses one server.
 * - **The host is gated before the credential goes on.** A disallowed or
 *   private-range target is rejected while the request is still anonymous, so a
 *   misconfigured URL cannot be used to post a token at an internal address.
 *   The vault client re-checks the host as defence in depth; this mirrors
 *   {@see \Netresearch\NrLlm\Service\SetupWizard\SecureHttpDispatchTrait},
 *   which cannot be reused here because it can neither authenticate per server
 *   nor set a timeout.
 *
 * The timeout it sets is not its own (ADR-170). Every call is given the
 * operation's {@see McpOperationDeadline} and takes what is left of it, because
 * a per-request budget multiplies by the number of legs the operation happens
 * to need.
 */
final class McpHttpTransport
{
    /**
     * Bounds what a hostile or broken server can push into memory before we
     * decide the body is not a JSON-RPC response.
     */
    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    /**
     * Test seam: when set, requests bypass the vault client entirely. Never set
     * in production — the vault path is what audits the call and injects the
     * credential.
     */
    private ?ClientInterface $configuredHttpClient = null;

    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly SecureHttpClientFactory $httpClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @internal test seam only
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
    }

    /**
     * Send one JSON-RPC request and return its `result` object.
     *
     * `durationMs` is the wall time of the exchange itself — the request going
     * out until the body has been read — and excludes encoding and decoding.
     * It is returned rather than recorded here (ADR-154): this class is a DI
     * singleton and one operation is several round trips, so a transport that
     * stored the number would either write once per round trip or keep mutable
     * per-server state. {@see McpClient} owns the operation and records once.
     *
     * @param array<string, mixed> $params
     * @param McpOperationDeadline $deadline the operation's remaining budget,
     *                                       which this leg spends from
     *
     * @throws McpTransportException on any outcome that is not a JSON-RPC result
     *
     * @return array{result: array<string, mixed>, sessionId: string|null, durationMs: int}
     */
    public function call(
        McpServerRecord $server,
        string $method,
        array $params,
        McpOperationDeadline $deadline,
        ?string $sessionId = null,
    ): array {
        $startedAt = hrtime(true);

        $response = $this->send($server, $this->encode($server, [
            'jsonrpc' => '2.0',
            // A single request per connection, so the id only has to be
            // present and echoable, not unique across calls.
            'id'      => 1,
            'method'  => $method,
            'params'  => $params === [] ? new stdClass() : $params,
        ]), $deadline, $sessionId);

        $durationMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);

        return [
            'result'     => $this->decodeResult($server, $response['body'], $response['status'], $response['contentType']),
            'sessionId'  => $response['sessionId'],
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Send one JSON-RPC notification, which by definition has no reply.
     *
     * A notification carries no `id`; a server answers it with 202 and an empty
     * body. Anything it does send back is discarded rather than parsed — there
     * is no result to take from it.
     *
     * @param array<string, mixed> $params
     * @param McpOperationDeadline $deadline the operation's remaining budget,
     *                                       which this leg spends from
     *
     * @throws McpTransportException when the server could not be reached
     */
    public function notify(
        McpServerRecord $server,
        string $method,
        array $params,
        McpOperationDeadline $deadline,
        ?string $sessionId = null,
    ): void {
        $this->send($server, $this->encode($server, [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params === [] ? new stdClass() : $params,
        ]), $deadline, $sessionId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws McpTransportException when the payload cannot be encoded
     */
    private function encode(McpServerRecord $server, array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw McpTransportException::forMalformedResponse($server->identifier, 'the request could not be encoded: ' . $e->getMessage());
        }
    }

    /**
     * @throws McpTransportException
     *
     * @return array{status: int, body: string, contentType: string, sessionId: string|null}
     */
    private function send(McpServerRecord $server, string $body, McpOperationDeadline $deadline, ?string $sessionId): array
    {
        // Checked before anything is built, and outside the catch below, so an
        // exhausted budget cannot be reported as a far side that failed. It is
        // also checked here rather than in `clientFor()` because the test seam
        // above bypasses that builder, and a bound only the production path
        // applies is a bound nothing asserts.
        if ($deadline->isExhausted()) {
            throw McpTransportException::forExhaustedDeadline($server->identifier, $deadline->totalSeconds());
        }

        $request = $this->requestFactory
            ->createRequest('POST', $server->url)
            ->withHeader('Content-Type', 'application/json')
            // JSON only, stated honestly: this client does not consume an event
            // stream, so it does not claim to accept one. A server that can
            // only answer with a stream rejects the request, which is a clearer
            // failure than a body we would refuse after the fact.
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        if ($sessionId !== null && $sessionId !== '') {
            $request = $request->withHeader('Mcp-Session-Id', $sessionId);
        }

        $host = $request->getUri()->getHost();

        try {
            if ($this->configuredHttpClient instanceof ClientInterface) {
                $response = $this->configuredHttpClient->sendRequest($request);
            } else {
                // Anonymous host gate first — see the class docblock.
                if (!$this->httpClientFactory->isHostAllowed($host)) {
                    throw McpTransportException::forRefusedHost($server->identifier, $host);
                }

                $response = $this->clientFor($server, $deadline->legTimeoutSeconds())->sendRequest($request);
            }
        } catch (McpTransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw McpTransportException::forTransportFailure($server->identifier, $e->getMessage());
        }

        $status = $response->getStatusCode();
        // 3xx counts as a failure rather than a redirect to follow: following
        // one would take the credential to a host the gate above never saw.
        if ($status >= 300) {
            throw McpTransportException::forStatus($server->identifier, $status);
        }

        $returnedSession = $response->getHeaderLine('Mcp-Session-Id');

        return [
            'status'      => $status,
            'body'        => $this->readBounded($response->getBody()),
            'contentType' => $response->getHeaderLine('Content-Type'),
            'sessionId'   => $returnedSession === '' ? null : $returnedSession,
        ];
    }

    /**
     * Read at most {@see self::MAX_RESPONSE_BYTES}, in chunks.
     *
     * A single `read()` may return fewer bytes than asked for even when more
     * are available, so one call is not a body; and `getContents()` would let a
     * server decide how much memory we spend.
     */
    private function readBounded(StreamInterface $stream): string
    {
        $body = '';

        while (!$stream->eof() && strlen($body) < self::MAX_RESPONSE_BYTES) {
            $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES - strlen($body)));
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * A client bound to this one server's credential, built fresh every call.
     *
     * `$timeoutSeconds` is what the operation has left, not a constant
     * (ADR-170). It is never zero or less:
     * {@see McpOperationDeadline::MINIMUM_LEG_SECONDS} explains why that would
     * remove the bound rather than tighten it.
     *
     * @throws McpTransportException when a declared credential does not resolve
     */
    private function clientFor(McpServerRecord $server, int $timeoutSeconds): ClientInterface
    {
        $client = $this->vault->http()
            ->withReason(sprintf('nr-llm MCP call to "%s"', $server->identifier))
            ->withTimeout($timeoutSeconds);

        if ($server->authCredential === '') {
            return $client;
        }

        // Fail closed. An MCP server that was configured with a credential is
        // not one to try anonymously: the anonymous call would either be
        // refused, which hides the real fault behind a 401, or succeed with
        // less authority than the operator intended.
        if (!$this->vault->exists($server->authCredential)) {
            throw McpTransportException::forMissingCredential($server->identifier);
        }

        $placement = SecretPlacement::tryFrom($server->authPlacement) ?? SecretPlacement::Bearer;
        $options   = [];
        if ($placement === SecretPlacement::Header) {
            if ($server->authHeaderName === '') {
                throw McpTransportException::forMissingCredential($server->identifier);
            }

            $options['headerName'] = $server->authHeaderName;
        }

        // The plaintext never enters this process: the vault client injects it
        // as it writes the request.
        return $client->withAuthentication($server->authCredential, $placement, $options);
    }

    /**
     * @throws McpTransportException
     *
     * @return array<string, mixed>
     */
    private function decodeResult(McpServerRecord $server, string $body, int $status, string $contentType): array
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType !== '' && $mediaType !== 'application/json') {
            throw McpTransportException::forUnsupportedContentType($server->identifier, $mediaType);
        }

        if (trim($body) === '') {
            throw McpTransportException::forMalformedResponse($server->identifier, sprintf('empty body with status %d', $status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw McpTransportException::forMalformedResponse($server->identifier, $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw McpTransportException::forMalformedResponse($server->identifier, 'the body is not a JSON object');
        }

        if (isset($decoded['error']) && \is_array($decoded['error'])) {
            $code    = \is_int($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : 0;
            $message = \is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : 'no message';

            throw McpTransportException::forRpcError($server->identifier, $code, $message);
        }

        $result = $decoded['result'] ?? null;
        if (!\is_array($result)) {
            throw McpTransportException::forMalformedResponse($server->identifier, 'the response carries neither a result nor an error');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }
}
