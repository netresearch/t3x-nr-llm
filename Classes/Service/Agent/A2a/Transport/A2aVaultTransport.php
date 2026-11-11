<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

use GuzzleHttp\Exception\ConnectException;
use InvalidArgumentException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchTarget;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\StreamingHttpClientInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
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
 *    (netresearch/t3x-nr-vault#521). A host name is never sent unpinned: an
 *    nr-vault without that interface refuses the request (`PIN_UNSUPPORTED`).
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

    /**
     * nr-vault 1.5.0's interface (netresearch/t3x-nr-vault#521). Named as a
     * string, and the method checked as well, because the nr-vault versions
     * nr-llm allows today (`^0.16.0 || ^1.1`) do not have the class: an
     * `instanceof` against it would not pass static analysis there. Once the
     * constraint requires the release, this becomes a plain `instanceof`.
     */
    private const PIN_INTERFACE = 'Netresearch\\NrVault\\Http\\PinnedAddressHttpClientInterface';

    private const PIN_METHOD = 'withPinnedAddresses';

    public function __construct(
        private A2aUrlGuardFactory $guardFactory,
        private VaultServiceInterface $vault,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function send(A2aHttpRequest $request): A2aTransportResult
    {
        $target = $this->guardFactory->create()->check(A2aUrlGuardFactory::GROUP_AGENT, $request->url);
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
                'the installed nr-vault cannot send to caller-checked addresses (PinnedAddressHttpClientInterface, netresearch/t3x-nr-vault#521); nothing was sent',
            );
        }

        $psr = $this->requestFactory->createRequest($request->method, $target->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        if ($request->body !== '') {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }

        // Streamed when nr-vault can (>= 1.1, curl-multi), so the size limit
        // stops the transfer instead of judging a body that was already read
        // whole (A2A-C8). nr-vault without streaming buffers the body first.
        $streaming = $client instanceof StreamingHttpClientInterface && $client->supportsStreaming();
        try {
            $response = $streaming ? $client->sendStreaming($psr) : $client->sendRequest($psr);
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

        return $this->read($response, $request->maxResponseBytes);
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
            // curl says "Connection timed out after …" (or "Resolving timed
            // out") while connecting, "Operation timed out after … bytes
            // received" once the request is under way.
            return preg_match('/(?:connection|resolving) tim(?:ed|e)[ -]?out/i', $e->getMessage()) === 1
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
        $interfaces = class_implements($client);
        if (!is_array($interfaces) || !isset($interfaces[self::PIN_INTERFACE]) || !method_exists($client, self::PIN_METHOD)) {
            return null;
        }

        $pinned = $client->{self::PIN_METHOD}($target->host, $target->addresses);

        return $pinned instanceof VaultHttpClientInterface ? $pinned : null;
    }

    private function read(ResponseInterface $response, int $maxBytes): A2aTransportResult
    {
        $body   = $response->getBody();
        $buffer = '';
        try {
            // Read at most one byte past the limit. On a streaming body an
            // empty read before eof() means "nothing arrived yet"; nr-vault's
            // time bound ends a stalled transfer with an exception.
            while (!$body->eof() && strlen($buffer) <= $maxBytes) {
                $chunk = $body->read(min(65536, $maxBytes + 1 - strlen($buffer)));
                if ($chunk === '') {
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
