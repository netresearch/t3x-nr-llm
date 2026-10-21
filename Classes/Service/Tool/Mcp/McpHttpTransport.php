<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\McpAuthenticationMode;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthOperationGuard;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Http\CancellableHttpClientInterface;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Throwable;

/**
 * One JSON-RPC round trip to one MCP server over HTTP (ADR-116).
 *
 * It allocates a distinct request ID using bounded scalar state, sends through
 * the vault client and validates the correlated `result` object (ADR-227).
 * Sessions, the initialize handshake and pagination live in {@see McpClient},
 * so this class stays assertable against a fake PSR-18 client.
 *
 * Two properties are load-bearing and neither is an accident:
 *
 * - **The client is built per server, per call.** It is never memoised on the
 *   instance. This class is a DI singleton, so a cached authenticated client
 *   would send the first server's token to the second — a credential leak
 *   between two operator-configured third parties, which is exactly the kind
 *   of mistake that never shows up in a test that uses one server.
 * - **The host is gated before the credential goes on.** Production calls
 *   consult the vault host policy before resolving or configuring credentials.
 *   An exact operator allowlist entry may permit an internal target; wildcard
 *   entries do not override the private-address check. The vault client
 *   re-checks the host as defence in depth; this mirrors
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
     * @return array{result: array<string, mixed>, sessionId: string|null, durationMs: int}
     *
     * @throws McpTransportException on any outcome that is not a JSON-RPC result
     */
    public function call(
        McpServerRecord $server,
        string $method,
        array $params,
        McpOperationDeadline $deadline,
        ?string $sessionId = null,
        ?CancellationSignalInterface $cancellation = null,
        ?McpCredentialSessionInterface $credentials = null,
    ): array {
        $requestId = $this->nextRequestId($server);
        $startedAt = hrtime(true);

        $response = $this->send(
            $server,
            $this->encode(
                $server,
                [
                    'jsonrpc' => '2.0',
                    'id'      => $requestId,
                    'method'  => $method,
                    'params'  => $params === [] ? new stdClass() : $params,
                ],
            ),
            $deadline,
            $sessionId,
            $cancellation,
            $credentials,
        );

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1000000);

        return [
            'result' => $this->decodeResult(
                $server,
                $response['body'],
                $response['status'],
                $response['contentType'],
                $requestId,
            ),
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
        ?CancellationSignalInterface $cancellation = null,
        ?McpCredentialSessionInterface $credentials = null,
    ): void {
        $this->send(
            $server,
            $this->encode(
                $server,
                [
                    'jsonrpc' => '2.0',
                    'method'  => $method,
                    'params'  => $params === [] ? new stdClass() : $params,
                ],
            ),
            $deadline,
            $sessionId,
            $cancellation,
            $credentials,
            false,
        );
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
     * @return array{status: int, body: string, contentType: string, sessionId: string|null}
     *
     * @throws McpTransportException
     */
    private function send(
        McpServerRecord $server,
        string $body,
        McpOperationDeadline $deadline,
        ?string $sessionId,
        ?CancellationSignalInterface $cancellation = null,
        ?McpCredentialSessionInterface $credentials = null,
        bool $replyExpected = true,
    ): array {
        // Checked before anything is built, and outside the catch below, so an
        // exhausted budget cannot be reported as a far side that failed. It is
        // also checked here rather than in `clientFor()` because the test seam
        // above bypasses that builder, and a bound only the production path
        // applies is a bound nothing asserts.
        if ($deadline->isExhausted()) {
            throw McpTransportException::forExhaustedDeadline(
                $server->identifier,
                $deadline->totalSeconds(),
            );
        }

        $request = $this->requestFactory
            ->createRequest('POST', $server->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream($body));

        if ($sessionId !== null && $sessionId !== '') {
            $request = $request->withHeader('Mcp-Session-Id', $sessionId);
        }

        $host = $request->getUri()->getHost();

        try {
            $client = $this->requestClient(
                $server,
                $host,
                $deadline,
                $credentials,
                $cancellation,
            );

            // Feature-detected rather than version-gated, which is the shape
            // nr-vault's interface was made for (#774): it is additive, so a
            // client that predates it — or a platform without `curl_multi` —
            // simply takes the blocking send it always took. Cancellation is
            // the only difference between the two branches; both run the same
            // scheme allowlist, host allowlist, credential injection and audit
            // write inside nr-vault.
            $response = $cancellation instanceof CancellationSignalInterface && $client instanceof CancellableHttpClientInterface && $client->supportsCancellation() ? $client->sendCancellable($request, $cancellation) : $client->sendRequest($request);
        } catch (McpTransportException $e) {
            throw $e;
        } catch (RequestCancelledException) {
            // Before the generic branch below, which would otherwise report an
            // operator's cancel as a server that could not be reached. Whether
            // the credential went out is nr-vault's row to say -- it writes
            // `http_call_cancelled` for a transfer in flight and
            // `http_call_cancelled_before_send` when the signal was already
            // true on entry -- so nothing is claimed about it here.
            throw McpTransportException::forCancelledCall($server->identifier);
        } catch (Throwable $e) {
            throw $server->authenticationMode() === McpAuthenticationMode::DELEGATED ? McpTransportException::forDelegatedAuthFailure(
                $server->identifier,
                'credential_transport_failed',
            ) : McpTransportException::forTransportFailure(
                $server->identifier,
                $e->getMessage(),
            );
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
            'body'        => $this->readResponseBody($server, $response, $replyExpected),
            'contentType' => $response->getHeaderLine('Content-Type'),
            'sessionId'   => $returnedSession === '' ? null : $returnedSession,
        ];
    }

    /**
     * Read at most the configured body bound; reply-bearing bodies must reach EOF.
     * An exact-limit response uses at most one extra probe byte to prove completion.
     */
    private function readBounded(
        McpServerRecord $server,
        StreamInterface $stream,
        bool $requireComplete,
    ): string {
        $body = '';
        while (!$this->streamAtEnd($stream) && strlen($body) < self::MAX_RESPONSE_BYTES) {
            $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES - strlen($body)));
            if ($chunk === '') {
                if ($requireComplete && !$this->streamAtEnd($stream)) {
                    throw McpTransportException::forMalformedResponse(
                        $server->identifier,
                        'the reply body ended reading without confirmed EOF',
                    );
                }

                break;
            }

            $body .= $chunk;
        }

        if ($requireComplete && !$this->streamAtEnd($stream)) {
            $probe = $stream->read(1);
            if ($probe !== '' || !$this->streamAtEnd($stream)) {
                throw McpTransportException::forMalformedResponse(
                    $server->identifier,
                    'the reply body is oversized or incomplete',
                );
            }
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
    private function clientFor(
        McpServerRecord $server,
        int $timeoutSeconds,
        ?string $delegatedCredential = null,
    ): ClientInterface {
        $client = $this->vault
            ->http()
            ->withReason(sprintf('nr-llm MCP call to "%s"', $server->identifier))
            ->withTimeout($timeoutSeconds);

        if ($delegatedCredential !== null) {
            if (!$this->vault->exists($delegatedCredential)) {
                throw McpTransportException::forDelegatedAuthFailure(
                    $server->identifier,
                    'credential_expired',
                );
            }

            return $client->withAuthentication(
                $delegatedCredential,
                SecretPlacement::Bearer,
            );
        }

        return $this->legacyAuthentication($server, $client);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws McpTransportException
     */
    private function decodeResult(
        McpServerRecord $server,
        string $body,
        int $status,
        string $contentType,
        string $requestId,
    ): array {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType === 'text/event-stream') {
            $body = $this->unframeEventStream($server, $body, $requestId);
        } elseif ($mediaType !== '' && $mediaType !== 'application/json') {
            throw McpTransportException::forUnsupportedContentType(
                $server->identifier,
                $mediaType,
            );
        }

        if (trim($body) === '') {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                sprintf('empty body with status %d', $status),
            );
        }

        $decoded = $this->validatedEnvelope($server, $body, $requestId);
        if (property_exists($decoded, 'error')) {
            /** @var stdClass $error */
            $error = $decoded->error;
            /** @var int $code */
            $code = $error->code;
            /** @var string $message */
            $message = $error->message;
            throw McpTransportException::forRpcError(
                $server->identifier,
                $code,
                $message,
            );
        }

        /** @var array{result: array<string, mixed>} $associative */
        $associative = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $associative['result'];
    }

    /**
     * Select exactly one correlated response from the complete bounded SSE body.
     * Notification/request-only frames and non-response framing retain their
     * existing ignored behavior. Validate all response-looking frames before
     * returning even a valid remote error, so later duplicates cannot be hidden.
     *
     * @throws McpTransportException
     */
    private function unframeEventStream(
        McpServerRecord $server,
        string $body,
        string $requestId,
    ): string {
        $messages = [];
        $data     = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if ($line === '') {
                if ($data !== []) {
                    $messages[] = implode("\n", $data);
                    $data       = [];
                }

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $payload = substr($line, 5);
                $data[]  = str_starts_with($payload, ' ') ? substr($payload, 1) : $payload;
            }
        }

        if ($data !== []) {
            $messages[] = implode("\n", $data);
        }

        $reply = null;
        foreach ($messages as $message) {
            $decoded = json_decode($message);
            if (!$decoded instanceof stdClass || !property_exists($decoded, 'result') && !property_exists($decoded, 'error')) {
                continue;
            }

            $this->validatedEnvelope($server, $message, $requestId);
            if ($reply !== null) {
                throw McpTransportException::forMalformedResponse(
                    $server->identifier,
                    'the event stream carried more than one response',
                );
            }

            $reply = $message;
        }

        if ($reply === null) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                $messages === [] ? 'the event stream carried no message' : 'the event stream carried no response to the request',
            );
        }

        return $reply;
    }

    /**
     * Validate the mode even through the protocol test seam. A delegated server
     * must never fall back to its legacy credential or an anonymous request.
     */
    private function credentialFor(
        McpServerRecord $server,
        ?McpCredentialSessionInterface $session,
    ): ?string {
        $mode = $server->authenticationMode();
        if (!$mode instanceof McpAuthenticationMode || $mode === McpAuthenticationMode::DELEGATED && !$session instanceof McpCredentialSessionInterface) {
            throw McpTransportException::forDelegatedAuthFailure(
                $server->identifier,
                'credential_session_missing',
            );
        }

        if ($mode === McpAuthenticationMode::LEGACY && $session instanceof McpCredentialSessionInterface) {
            throw McpTransportException::forDelegatedAuthFailure(
                $server->identifier,
                'unexpected_credential_session',
            );
        }

        return $session?->credentialIdentifier();
    }

    /**
     * Gate the MCP destination before renewing or resolving a credential, then
     * charge authentication work before choosing the remaining wire timeout.
     * The protocol-only seam follows the same check after credential resolution.
     */
    private function requestClient(
        McpServerRecord $server,
        string $host,
        McpOperationDeadline $deadline,
        ?McpCredentialSessionInterface $credentials,
        ?CancellationSignalInterface $cancellation,
    ): ClientInterface {
        if (!$this->configuredHttpClient instanceof ClientInterface && !$this->httpClientFactory->isHostAllowed($host)) {
            throw McpTransportException::forRefusedHost($server->identifier, $host);
        }

        $credential = $this->credentialFor($server, $credentials);
        McpAuthOperationGuard::assertAlive(
            $server->identifier,
            $deadline,
            $credentials instanceof McpCredentialSessionInterface ? $cancellation : null,
        );

        return $this->configuredHttpClient instanceof ClientInterface ? $this->configuredHttpClient : $this->clientFor($server, $deadline->legTimeoutSeconds(), $credential);
    }

    private function legacyAuthentication(
        McpServerRecord $server,
        VaultHttpClientInterface $client,
    ): ClientInterface {
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
        return $client->withAuthentication(
            $server->authCredential,
            $placement,
            $options,
        );
    }

    private string $requestNamespace = '';

    private ?int $requestProcess = null;

    private int $requestSequence = 0;

    /**
     * Validate only the MCP reply envelope; nested result shapes stay associative.
     */
    private function validatedEnvelope(
        McpServerRecord $server,
        string $body,
        string $requestId,
    ): stdClass {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the reply is not valid JSON',
            );
        }

        if (!$decoded instanceof stdClass) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the reply root is not a JSON object',
            );
        }

        if (($decoded->jsonrpc ?? null) !== '2.0' || ($decoded->id ?? null) !== $requestId) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the reply version or request ID does not match',
            );
        }

        $hasResult = property_exists($decoded, 'result');
        $hasError  = property_exists($decoded, 'error');
        if ($hasResult === $hasError || property_exists($decoded, 'method')) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the reply must carry exactly one response branch and no method',
            );
        }

        if ($hasResult) {
            if (!$decoded->result instanceof stdClass) {
                throw McpTransportException::forMalformedResponse(
                    $server->identifier,
                    'the MCP result is not a JSON object',
                );
            }

            return $decoded;
        }

        if (!$decoded->error instanceof stdClass || !is_string($decoded->error->message ?? null)) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the RPC error must be an object with a string message',
            );
        }

        $rawError = $this->rawObjectMember($body, 'error');
        $rawCode  = $rawError === null ? null : $this->rawObjectMember($rawError, 'code');
        $code     = $rawCode === null ? null : $this->integralErrorCode($rawCode);
        if ($code === null) {
            throw McpTransportException::forMalformedResponse(
                $server->identifier,
                'the RPC error code is not an exactly representable integral JSON number',
            );
        }

        $decoded->error->code = $code;

        return $decoded;
    }

    /**
     * Read the last decoded-key match in an already valid JSON object. This is
     * confined to the root error member and its code: json_decode also selects
     * the last duplicate key, including escaped spellings. Do not inspect text
     * inside string values or nested objects as if it were a sibling member.
     */
    private function rawObjectMember(string $json, string $wanted): ?string
    {
        $length = strlen($json);
        $offset = strspn($json, " \t\r\n") + 1;
        $found  = null;
        while ($offset < $length) {
            $offset += strspn($json, " \t\r\n", $offset);
            if ($json[$offset] === '}') {
                break;
            }

            $keyStart = $offset;
            $offset   = $this->jsonValueEnd($json, $offset);
            $key      = json_decode(substr($json, $keyStart, $offset - $keyStart), true);
            $offset += strspn($json, " \t\r\n", $offset);
            ++$offset;
            // The colon is guaranteed by the preceding JSON validation.
            $offset += strspn($json, " \t\r\n", $offset);
            $valueStart = $offset;
            $offset     = $this->jsonValueEnd($json, $offset);
            if ($key === $wanted) {
                $found = substr($json, $valueStart, $offset - $valueStart);
            }

            $offset += strspn($json, " \t\r\n", $offset);
            if ($offset >= $length || $json[$offset] !== ',') {
                break;
            }

            ++$offset;
        }

        return $found;
    }

    /**
     * Skip one already validated JSON value, honoring string escapes and nesting.
     */
    private function jsonValueEnd(string $json, int $offset): int
    {
        $length     = strlen($json);
        $quoted     = false;
        $escaped    = false;
        $depth      = 0;
        $structured = $json[$offset] === '{' || $json[$offset] === '[';
        $string     = $json[$offset] === '"';
        for ($index = $offset; $index < $length; ++$index) {
            $character = $json[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                    if ($string) {
                        return $index + 1;
                    }
                }

                continue;
            }

            if ($character === '"') {
                $quoted = true;
            } elseif ($structured && ($character === '{' || $character === '[')) {
                ++$depth;
            } elseif ($structured && ($character === '}' || $character === ']')) {
                --$depth;
                if ($depth === 0) {
                    return $index + 1;
                }
            } elseif (!$structured && !$string && str_contains(",}] \t\r\n", $character)) {
                return $index;
            }
        }

        return $length;
    }

    /**
     * Validate only error.code against its exact decimal wire value. Exponents
     * are bounded before expansion; casting is performed only after exact range
     * comparison. Rounded json_decode floats never decide acceptance.
     */
    private function integralErrorCode(string $token): ?int
    {
        if (preg_match(
            '/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D',
            $token,
            $parts,
            PREG_UNMATCHED_AS_NULL,
        ) !== 1) {
            return null;
        }

        $fraction = $parts[3] ?? '';
        $digits   = ltrim($parts[2] . $fraction, '0');
        if ($digits === '') {
            return 0;
        }

        $exponentToken  = $parts[4] ?? '0';
        $exponentDigits = ltrim(ltrim($exponentToken, '+-'), '0');
        $maximumShift   = strlen($digits) + strlen($fraction) + strlen((string) PHP_INT_MAX);
        $bound          = (string) $maximumShift;
        if (strlen($exponentDigits) > strlen($bound) || strlen($exponentDigits) === strlen($bound) && strcmp($exponentDigits, $bound) > 0) {
            return null;
        }

        $exponent = (int) $exponentDigits;
        if (str_starts_with($exponentToken, '-')) {
            $exponent = -$exponent;
        }

        $shift = $exponent - strlen($fraction);
        if ($shift < 0) {
            $remove = -$shift;
            if ($remove >= strlen($digits) || strspn($digits, '0', strlen($digits) - $remove) !== $remove) {
                return null;
            }

            $digits = substr($digits, 0, strlen($digits) - $remove);
        }

        $negative = $parts[1] === '-';
        $limit    = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if ($shift > 0) {
            if (strlen($digits) + $shift > strlen($limit)) {
                return null;
            }

            $digits .= str_repeat('0', $shift);
        }

        if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            return null;
        }

        return (int) (($negative ? '-' : '') . $digits);
    }

    /**
     * Allocate before HTTP contact; a fork starts a new random namespace.
     */
    private function nextRequestId(McpServerRecord $server): string
    {
        $process = getmypid();
        if ($process === false) {
            throw McpTransportException::forTransportFailure(
                $server->identifier,
                'request ID process identity is unavailable',
            );
        }

        if ($this->requestProcess !== $process) {
            try {
                $namespace = bin2hex(random_bytes(16));
            } catch (Throwable) {
                throw McpTransportException::forTransportFailure(
                    $server->identifier,
                    'request ID allocation failed',
                );
            }

            $this->requestNamespace = $namespace;
            $this->requestProcess   = $process;
            $this->requestSequence  = 0;
        }

        if ($this->requestSequence === PHP_INT_MAX) {
            throw McpTransportException::forTransportFailure(
                $server->identifier,
                'request ID sequence exhausted',
            );
        }

        ++$this->requestSequence;

        return 'nrllm-' . $this->requestNamespace . '-' . $this->requestSequence;
    }

    /**
     * @phpstan-impure A read changes the stream's EOF observation.
     */
    private function streamAtEnd(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    /**
     * Keep failures obtaining or reading the remote body inside the typed MCP
     * contract without repeating stream exception text or response contents.
     */
    private function readResponseBody(
        McpServerRecord $server,
        ResponseInterface $response,
        bool $requireComplete,
    ): string {
        try {
            return $this->readBounded($server, $response->getBody(), $requireComplete);
        } catch (McpTransportException $e) {
            throw $e;
        } catch (Throwable) {
            throw McpTransportException::forMalformedResponse($server->identifier, 'the response body could not be read');
        }
    }
}
