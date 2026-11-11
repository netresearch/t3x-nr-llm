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
