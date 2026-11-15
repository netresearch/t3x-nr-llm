<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NullResponse;

/**
 * One JSON-RPC response of the server role and its HTTP status (spec 051
 * A2A-P1, A2A-P6): 200 for a result and for an error the protocol answers in
 * the body; 401, 413, 429, 500, 503 and 504 where the specification names
 * them.
 *
 * A streaming method that passed every check answers with a stream instead
 * (A2A-SSE1): {@see self::toResponse()} then writes the events to the client
 * itself and returns a NullResponse, which TYPO3 does not emit again. Every
 * refusal of a streaming method is an ordinary JSON-RPC response, written
 * before the first byte of a stream would be.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRpcReply
{
    /**
     * @param array<string, mixed>               $body
     * @param array<string, string>              $headers
     * @param (Closure(A2aSseWriter): void)|null $stream
     */
    private function __construct(
        public int $status,
        public array $body,
        public array $headers,
        private ?Closure $stream = null,
        private int $maxFrameBytes = 0,
    ) {}

    /**
     * @param array<string, mixed> $result
     */
    public static function result(int|string|null $id, array $result): self
    {
        // An empty result is the proto's `Empty`, a JSON object, never `[]`.
        return new self(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new stdClass() : $result], []);
    }

    public static function error(int|string|null $id, A2aRpcError $error): self
    {
        return new self($error->httpStatus, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()], $error->responseHeaders());
    }

    /**
     * @param Closure(A2aSseWriter): void $stream writes the events and releases what it holds
     */
    public static function stream(Closure $stream, int $maxFrameBytes): self
    {
        return new self(200, [], [], $stream, $maxFrameBytes);
    }

    public function isStream(): bool
    {
        return $this->stream instanceof Closure;
    }

    /**
     * Write the stream into a writer; a test passes one with a sink.
     */
    public function streamTo(A2aSseWriter $writer): void
    {
        if ($this->stream instanceof Closure) {
            ($this->stream)($writer);
        }
    }

    public function toResponse(): ResponseInterface
    {
        if ($this->stream instanceof Closure) {
            $writer = new A2aSseWriter($this->maxFrameBytes);
            $writer->open();
            ($this->stream)($writer);

            return new NullResponse();
        }

        $response = new JsonResponse($this->body, $this->status, ['Content-Type' => A2aProtocol::CONTENT_TYPE, 'Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
