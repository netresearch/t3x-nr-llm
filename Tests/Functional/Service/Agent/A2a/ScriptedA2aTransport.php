<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use RuntimeException;

/**
 * A transport that answers from a script and keeps every request it was given.
 *
 * Each step receives the request and returns the result; `json()` builds a
 * JSON-RPC response that echoes the request's id, as a real server does.
 */
final class ScriptedA2aTransport implements A2aTransportInterface
{
    /** @var list<A2aHttpRequest> */
    public array $requests = [];

    /** @var list<Closure(A2aHttpRequest): A2aTransportResult> */
    private array $steps = [];

    /** @var list<Closure(A2aHttpRequest, Closure(string): bool): A2aTransportResult> */
    private array $streams = [];

    /**
     * @param Closure(A2aHttpRequest): A2aTransportResult $step
     */
    public function then(Closure $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    /**
     * A 200 JSON-RPC response with this result (or error) and the request's id.
     *
     * @param array<string, mixed> $member `['result' => …]` or `['error' => …]`
     */
    public function thenJson(array $member, int $status = 200): self
    {
        return $this->then(static fn (A2aHttpRequest $request): A2aTransportResult => A2aTransportResult::response(
            $status,
            'application/json',
            '',
            json_encode(['jsonrpc' => '2.0', 'id' => self::idOf($request)] + $member, JSON_THROW_ON_ERROR),
            false,
        ));
    }

    /**
     * A stream step: it receives the request and the byte sink and returns how
     * the stream ended.
     *
     * @param Closure(A2aHttpRequest, Closure(string): bool): A2aTransportResult $step
     */
    public function thenStream(Closure $step): self
    {
        $this->streams[] = $step;

        return $this;
    }

    /**
     * An event stream of these pieces (each written as it is, so a piece may
     * end in the middle of a line or of a UTF-8 sequence), then the end the
     * stream had: a normal close (null) or a broken connection.
     *
     * @param list<string|Closure(A2aHttpRequest): string> $pieces a closure gets the request, e.g. for its id
     */
    public function thenEvents(array $pieces, ?A2aTransportResult $end = null): self
    {
        return $this->thenStream(static function (A2aHttpRequest $request, Closure $onBytes) use ($pieces, $end): A2aTransportResult {
            foreach ($pieces as $piece) {
                if ($onBytes($piece instanceof Closure ? $piece($request) : $piece) === false) {
                    break;
                }
            }

            return $end ?? A2aTransportResult::response(200, 'text/event-stream', '', '', false);
        });
    }

    /**
     * One `data:` event holding a JSON-RPC result with the request's id.
     *
     * @param array<string, mixed> $result
     *
     * @return Closure(A2aHttpRequest): string
     */
    public static function event(array $result): Closure
    {
        return static fn (A2aHttpRequest $request): string => 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => self::idOf($request), 'result' => $result], JSON_THROW_ON_ERROR) . "\n\n";
    }

    public function stream(A2aHttpRequest $request, Closure $onBytes): A2aTransportResult
    {
        $this->requests[] = $request;
        $step             = array_shift($this->streams);
        if (!$step instanceof Closure) {
            throw new RuntimeException('The script has no stream step for request ' . count($this->requests), 4267806157);
        }

        return $step($request, $onBytes);
    }

    public function send(A2aHttpRequest $request): A2aTransportResult
    {
        $this->requests[] = $request;
        $step             = array_shift($this->steps);
        if (!$step instanceof Closure) {
            throw new RuntimeException('The script has no step for request ' . count($this->requests), 4267806156);
        }

        return $step($request);
    }

    /**
     * @return array<string, mixed>
     */
    public static function envelopeOf(A2aHttpRequest $request): array
    {
        $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('The request body is not an object', 7379053181);
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    public static function idOf(A2aHttpRequest $request): string
    {
        $id = self::envelopeOf($request)['id'] ?? null;

        return is_string($id) ? $id : '';
    }
}
