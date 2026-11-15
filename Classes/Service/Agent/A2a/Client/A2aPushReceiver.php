<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;

/**
 * The push endpoint of the A2A client role (spec 051 A2A-PUSH6, A2A-PUSH8,
 * A2A-SEC6, ADR-285): `POST <base>/push` of the configured push site.
 *
 * 1. The credential is `Authorization: Bearer <callback credential>` of an
 *    open registration. Missing, malformed, unknown, wrong, of a closed
 *    registration, of a run that no longer runs or waits, or of a remote
 *    agent record that is disabled, deleted or gone: 401 with
 *    `WWW-Authenticate: Bearer realm="a2a-push"`, one
 *    answer for all, nothing stored. `X-A2A-Notification-Token` is not read
 *    at all: it neither authenticates nor refuses (A2A-D8). Failures are
 *    logged with the source address, never the credential, and counted per
 *    address by the rate limiter `nrllm-a2a-push-auth` (10 per 15 minutes);
 *    over it, 429.
 * 2. A declared `Content-Length` above `a2a.client.maxPushBytes` is 413
 *    before a byte is read; the body is read in chunks up to one byte past
 *    the limit, and more is 413.
 * 3. The body must be a JSON object (a plain `StreamResponse`); otherwise
 *    400. Neither `A2A-Version` nor a content type is required: an
 *    `a2a-python` 1.3.0 sender sends neither.
 * 4. The body is stored sealed within the inbox bounds (429 over one), and
 *    only then the answer is 202: received, nothing more. The body decides
 *    nothing; the worker reads the task with GetTask.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushReceiver
{
    public const LIMITER_ID = 'nrllm-a2a-push-auth';

    private const LIMITER = ['id' => self::LIMITER_ID, 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'];

    /** Seconds a sender is asked to wait when the inbox is full. */
    private const FULL_RETRY_AFTER = 30;

    private LoggerInterface $logger;

    public function __construct(
        private A2aClientPushSettings $settings,
        private A2aPushRegistrationStore $registrations,
        private StorageInterface $limiterStorage,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(ServerRequestInterface $request, int $now): ResponseInterface
    {
        $credential   = preg_match('/^Bearer\s+(\S+)\s*$/i', $request->getHeaderLine('Authorization'), $match) === 1 ? $match[1] : '';
        $registration = $this->registrations->authenticate($credential);
        if ($registration === null) {
            return $this->refuse($request, $credential === '' ? 'no bearer credential' : 'unknown or wrong credential, a closed registration, an ended run, or a disabled or deleted remote agent record');
        }

        $limit  = $this->settings->maxBodyBytes();
        $length = $request->getHeaderLine('Content-Length');
        if (is_numeric($length) && (int) $length > $limit) {
            return $this->json(413, 'payload_too_large');
        }

        $body = $this->read($request, $limit);
        if ($body === null) {
            return $this->json(413, 'payload_too_large');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return $this->json(400, 'not_a_stream_response');
        }

        $stored = $this->registrations->store($registration, $body, $now, $this->settings->maxInboxPerTask(), $this->settings->maxInbox());

        return match ($stored) {
            A2aPushRegistrationStore::STORED => new Response(null, 202),
            A2aPushRegistrationStore::FULL   => $this->json(429, 'inbox_full', ['Retry-After' => (string) self::FULL_RETRY_AFTER]),
            default                          => $this->refuse($request, 'the registration closed while the callback arrived'),
        };
    }

    /**
     * The body up to the limit and not one byte further; null when it is
     * longer.
     */
    private function read(ServerRequestInterface $request, int $limit): ?string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof() && strlen($body) <= $limit) {
            $chunk = $stream->read(min(65536, $limit + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return strlen($body) > $limit ? null : $body;
    }

    private function refuse(ServerRequestInterface $request, string $why): ResponseInterface
    {
        $address = $this->sourceAddress($request);
        $state   = $this->limiter($address)->consume(1);
        if (!$state->isAccepted()) {
            return $this->json(429, 'rate_limited', ['Retry-After' => (string) max(1, $state->getRetryAfter()->getTimestamp() - time())]);
        }

        $this->logger->warning('A2A push callback refused: authentication failed', ['source' => $address, 'why' => $why]);

        return $this->json(401, 'unauthenticated', ['WWW-Authenticate' => 'Bearer realm="a2a-push"']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function json(int $status, string $error, array $headers = []): ResponseInterface
    {
        $response = new Response('php://temp', $status, ['Content-Type' => 'application/json'] + $headers);
        $response->getBody()->write('{"error":"' . $error . '"}');

        return $response;
    }

    private function sourceAddress(ServerRequestInterface $request): string
    {
        $params = $request->getAttribute('normalizedParams');
        $params = $params instanceof NormalizedParams ? $params : NormalizedParams::createFromRequest($request);

        return $params->getRemoteAddress();
    }

    private function limiter(string $address): LimiterInterface
    {
        $configuration = self::LIMITER;
        $node          = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['SYS', 'rateLimiter', self::LIMITER_ID] as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }

        if (is_array($node)) {
            $configuration = array_replace($configuration, $node);
        }

        return (new RateLimiterFactory($configuration, $this->limiterStorage))->create($address);
    }
}
