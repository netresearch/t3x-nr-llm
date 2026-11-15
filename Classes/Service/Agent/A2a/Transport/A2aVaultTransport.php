<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use InvalidArgumentException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchTarget;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Exception\AuditWriteException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\PinnedAddressHttpClientInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\StreamingHttpClientInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Sends A2A requests through nr-vault, pinned to the addresses the guard
 * checked (spec 051 A2A-S0-3, A2A-S0-3a, ADR-279).
 *
 * In this order, and the first refusal ends the request with nothing sent:
 *
 * 1. {@see A2aUrlGuardFactory}'s guard checks the URL for the `a2a_agent`
 *    egress group (ADR-202 checks: scheme, own sites, lists, port, proxy,
 *    public addresses) and returns the addresses it resolved.
 * 2. A credential is only sent over https (`CREDENTIAL_NEEDS_HTTPS`). It is an
 *    nr-vault identifier and must exist; nr-vault injects it as
 *    `Authorization: Bearer` for this one request, and writes its audit row.
 * 3. The client is pinned to the checked addresses with nr-vault's
 *    `PinnedAddressHttpClientInterface::withPinnedAddresses()`
 *    (netresearch/t3x-nr-vault#521, nr-vault 1.5.0). A host name is never sent
 *    unpinned: a client without that interface refuses the request
 *    (`PIN_UNSUPPORTED`).
 *    An IP literal needs no pin; the guard checked the literal itself.
 *
 * nr-vault's client follows no redirect and retries nothing; a 3xx is
 * returned as a response for the caller to refuse. Failures are classified,
 * never thrown: a connection that could not be opened sent nothing, and
 * anything after it may have reached the remote.
 *
 * Connect timeout: nr-vault offers one timeout per request, the total one.
 * The record's read timeout is that total; its connect timeout is bounded by
 * it and by TYPO3's `HTTP.connect_timeout`, not on its own.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aVaultTransport implements A2aTransportInterface
{
    use ErrorMessageSanitizerTrait;

    /**
     * curl errors that end a request before any byte of it left: a name did
     * not resolve, no connection was made, the TLS handshake or the proxy
     * failed. The set is Guzzle 8's `CURL_CONNECTION_ERRORS`, which Guzzle 7's
     * `ConnectException` covered too.
     */
    private const NOT_SENT_ERRNO = [5, 6, 7, 35, 51, 60, 83, 90, 91, 96, 97, 98, 101];

    private const CURLE_OPERATION_TIMEDOUT = 28;

    public function __construct(
        private A2aUrlGuardFactory $guardFactory,
        private VaultServiceInterface $vault,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function send(A2aHttpRequest $request): A2aTransportResult
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof A2aTransportResult) {
            return $prepared;
        }

        return $this->transfer($prepared[0], $request, $prepared[1]);
    }

    /**
     * A2A-SSE6: the same checks and pin as {@see self::send()}; an answer of
     * type `text/event-stream` is handed to $onBytes piece by piece as it
     * arrives, never held whole. Any other answer is read like a unary one.
     * Without nr-vault's streaming interface nothing is sent
     * (`STREAMING_UNSUPPORTED`): a buffered stream would arrive at its end.
     *
     * The request's timeout is the longest the stream may last; past it the
     * stream ends as `TIMEOUT_AFTER_SEND`. $onBytes returns false to stop
     * reading, which ends the stream as a response. An exception the callback
     * throws is not a lost connection: it reaches the caller.
     *
     * @param Closure(string): bool $onBytes
     */
    public function stream(A2aHttpRequest $request, Closure $onBytes): A2aTransportResult
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof A2aTransportResult) {
            return $prepared;
        }

        [$client, $target] = $prepared;
        if (!$client instanceof StreamingHttpClientInterface || !$client->supportsStreaming()) {
            return A2aTransportResult::notSent('STREAMING_UNSUPPORTED', 'this nr-vault cannot deliver a response while it arrives; nothing was sent');
        }

        $psr      = $this->psrRequest($request, $target);
        $deadline = microtime(true) + max(1, $request->timeoutSeconds);
        try {
            $response = $client->sendStreaming($psr);
        } catch (AuditWriteException $e) {
            return A2aTransportResult::maybeSent('AUDIT_WRITE_FAILED', $this->sanitizeErrorMessage($e->getMessage()));
        } catch (VaultException $e) {
            return A2aTransportResult::maybeSent('TRANSFER_ABORTED', $this->sanitizeErrorMessage($e->getMessage()));
        } catch (Throwable $e) {
            return $this->classify($e);
        }

        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($type !== 'text/event-stream' || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $this->read($response, $request->maxResponseBytes, true, $deadline);
        }

        return $this->pump($response, $onBytes, $deadline);
    }

    /**
     * Hands the body of an event stream to $onBytes until it ends, the
     * callback stops it, or the deadline passes.
     *
     * Public for the unit test of the loop; not part of the transport contract.
     *
     * @param Closure(string): bool $onBytes
     */
    public function pump(ResponseInterface $response, Closure $onBytes, float $deadline): A2aTransportResult
    {
        $body = $response->getBody();
        while (true) {
            // Only a failure of the connection is a lost connection; what the
            // callback throws is the caller's and reaches it unchanged.
            try {
                if ($body->eof()) {
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $body->close();

                    return A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'the stream did not end within the request timeout');
                }

                $chunk = $body->read(65536);
            } catch (Throwable $e) {
                return A2aTransportResult::maybeSent('CONNECTION_LOST', $this->sanitizeErrorMessage($e->getMessage()));
            }

            if ($chunk === '') {
                usleep(2000);

                continue;
            }

            if ($onBytes($chunk) === false) {
                $body->close();

                break;
            }
        }

        return A2aTransportResult::response($response->getStatusCode(), $response->getHeaderLine('Content-Type'), $response->getHeaderLine('ETag'), '', false);
    }

    /**
     * The checks before anything is sent, and the prepared client.
     *
     * @return A2aTransportResult|array{VaultHttpClientInterface, ExternalFetchTarget} a refusal, or the client and the checked target
     */
    private function prepare(A2aHttpRequest $request): A2aTransportResult|array
    {
        // A push webhook has host lists of its own (ADR-285); every other
        // request goes to a registered remote agent.
        $guard  = $request->egressGroup === A2aUrlGuardFactory::GROUP_EXTERNAL ? $this->guardFactory->createForPush() : $this->guardFactory->create();
        $target = $guard->check($request->egressGroup, $request->url);
        if (!$target->allowed) {
            return A2aTransportResult::notSent('ADDRESS_REFUSED', $target->reason);
        }

        if ($request->credential !== '' && strtolower((string) parse_url($target->url, PHP_URL_SCHEME)) !== 'https') {
            // The credential never travels in clear text, whatever the card says.
            return A2aTransportResult::notSent('CREDENTIAL_NEEDS_HTTPS', 'a credential is only sent over https; the URL is not https');
        }

        try {
            $client = $this->client($request, $target);
        } catch (A2aException|VaultException $e) {
            return A2aTransportResult::notSent('CREDENTIAL_UNAVAILABLE', $this->sanitizeErrorMessage($e->getMessage()));
        } catch (InvalidArgumentException $e) {
            // withPinnedAddresses() refuses a host or an address it cannot pin
            // (codes 1794317201 to 1794317203); nothing is sent unpinned.
            return A2aTransportResult::notSent('PIN_REFUSED', $this->sanitizeErrorMessage($e->getMessage()));
        }

        if (!$client instanceof VaultHttpClientInterface) {
            return A2aTransportResult::notSent(
                'PIN_UNSUPPORTED',
                'the vault HTTP client cannot send to caller-checked addresses (it does not implement PinnedAddressHttpClientInterface); nothing was sent',
            );
        }

        return [$client, $target];
    }

    private function psrRequest(A2aHttpRequest $request, ExternalFetchTarget $target): RequestInterface
    {
        $psr = $this->requestFactory->createRequest($request->method, $target->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        if ($request->body !== '') {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }

        return $psr;
    }

    /**
     * Sends the request through the prepared client and reads the response;
     * every failure from here on is classified, never thrown.
     */
    private function transfer(VaultHttpClientInterface $client, A2aHttpRequest $request, ExternalFetchTarget $target): A2aTransportResult
    {
        $psr = $this->psrRequest($request, $target);

        // Streamed when nr-vault can (>= 1.1, curl-multi), so the size limit
        // stops the transfer instead of judging a body that was already read
        // whole (A2A-C8). nr-vault without streaming buffers the body first.
        $streaming = $client instanceof StreamingHttpClientInterface && $client->supportsStreaming();
        // A local bound as well as nr-vault's: a peer that keeps the
        // connection open and sends nothing must not outlive the timeout.
        $deadline = microtime(true) + max(1, $request->timeoutSeconds);
        try {
            $response = $streaming ? $client->sendStreaming($psr) : $client->sendRequest($psr);
        } catch (AuditWriteException $e) {
            // nr-vault writes its audit row after the transfer; when that write
            // fails, the request may already have reached the remote.
            return A2aTransportResult::maybeSent('AUDIT_WRITE_FAILED', $this->sanitizeErrorMessage($e->getMessage()));
        } catch (VaultException $e) {
            if ($streaming) {
                // sendStreaming() also throws this when a time or buffer bound
                // is hit during the transfer; the request may have left.
                return A2aTransportResult::maybeSent('TRANSFER_ABORTED', $this->sanitizeErrorMessage($e->getMessage()));
            }

            // sendRequest(): nr-vault refuses before the transfer (scheme, host
            // policy, secret).
            return A2aTransportResult::notSent('VAULT_REFUSED', $this->sanitizeErrorMessage($e->getMessage()));
        } catch (Throwable $e) {
            return $this->classify($e);
        }

        return $this->read($response, $request->maxResponseBytes, $streaming, $deadline);
    }

    /**
     * Whether a failed transfer may have reached the remote.
     *
     * Guzzle 7 and 8 name their exceptions differently (8 adds
     * `ConnectTimeoutException`, `NetworkException` and more), but both write
     * curl's code into the message as `cURL error N:`. A connect error and a
     * timeout while connecting sent nothing; every other failure may have.
     */
    public function classify(Throwable $e): A2aTransportResult
    {
        $message = $this->sanitizeErrorMessage($e->getMessage());
        $errno   = preg_match('/cURL error (\d+):/', $e->getMessage(), $match) === 1 ? (int) $match[1] : null;
        if ($errno !== null && in_array($errno, self::NOT_SENT_ERRNO, true)) {
            return A2aTransportResult::notSent('CONNECT_FAILED', $message);
        }

        if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
            // curl says "Connection timed out after …", "Resolving timed out"
            // or, from curl 8, "Failed to connect to … after … ms: Timeout was
            // reached" while connecting, and "Operation timed out after …
            // bytes received" once the request is under way.
            return preg_match('/(?:connection|resolving) tim(?:ed|e)[ -]?out|failed to connect to /i', $e->getMessage()) === 1
                ? A2aTransportResult::notSent('CONNECT_TIMEOUT', $message)
                : A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', $message);
        }

        if ($e instanceof ConnectException && $errno === null) {
            return A2aTransportResult::notSent('CONNECT_FAILED', $message);
        }

        return A2aTransportResult::maybeSent('CONNECTION_LOST', $message);
    }

    /**
     * The client for this request, or null when it cannot be pinned.
     *
     * @throws A2aException             when the credential does not exist
     * @throws VaultException
     * @throws InvalidArgumentException
     */
    private function client(A2aHttpRequest $request, ExternalFetchTarget $target): ?VaultHttpClientInterface
    {
        $client = $this->vault->http()
            ->withReason($request->reason)
            ->withTimeout(max(1, $request->timeoutSeconds));

        if ($request->credential !== '') {
            if (!$this->vault->exists($request->credential)) {
                throw new A2aException(sprintf('the credential "%s" does not exist in nr-vault', $request->credential), 1799100300);
            }

            $client = $client->withAuthentication($request->credential, SecretPlacement::Bearer);
        }

        if ($target->resolvePin() === null) {
            // An IP literal: nothing is resolved, the guard checked the address itself.
            return $client;
        }

        return $this->pinned($client, $target);
    }

    /**
     * The client pinned to the checked addresses, or null when this nr-vault
     * cannot pin (fail closed: the caller refuses, never sends unpinned).
     *
     * @throws InvalidArgumentException when nr-vault refuses the host or an address
     */
    private function pinned(VaultHttpClientInterface $client, ExternalFetchTarget $target): ?VaultHttpClientInterface
    {
        if (!$client instanceof PinnedAddressHttpClientInterface) {
            return null;
        }

        return $client->withPinnedAddresses($target->host, $target->addresses);
    }

    /**
     * Reads the body up to one byte past the limit, and no longer than the
     * deadline (a `microtime(true)` value).
     *
     * On a streaming body an empty read before eof() means "nothing arrived
     * yet", so the loop polls; past the deadline the request may have reached
     * the remote and its answer was not read (`TIMEOUT_AFTER_SEND`, A2A-C6).
     * A buffered body that reads empty has nothing more to give.
     *
     * Public for the unit test of the loop; not part of the transport contract.
     */
    public function read(ResponseInterface $response, int $maxBytes, bool $streaming, float $deadline): A2aTransportResult
    {
        $body   = $response->getBody();
        $buffer = '';
        try {
            while (!$body->eof() && strlen($buffer) <= $maxBytes) {
                if (microtime(true) >= $deadline) {
                    $body->close();

                    return A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'the response did not arrive within the request timeout');
                }

                $chunk = $body->read(min(65536, $maxBytes + 1 - strlen($buffer)));
                if ($chunk === '') {
                    if (!$streaming) {
                        break;
                    }

                    usleep(2000);

                    continue;
                }

                $buffer .= $chunk;
            }
        } catch (Throwable $e) {
            return A2aTransportResult::maybeSent('CONNECTION_LOST', $this->sanitizeErrorMessage($e->getMessage()));
        }

        $oversized = strlen($buffer) > $maxBytes;
        if ($oversized) {
            // Stop the transfer; the rest is never read.
            $body->close();
        }

        return A2aTransportResult::response(
            $response->getStatusCode(),
            $response->getHeaderLine('Content-Type'),
            $response->getHeaderLine('ETag'),
            $oversized ? substr($buffer, 0, $maxBytes) : $buffer,
            $oversized,
        );
    }
}
