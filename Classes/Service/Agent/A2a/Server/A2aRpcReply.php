<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * One JSON-RPC response of the server role and its HTTP status (spec 051
 * A2A-P1, A2A-P6): 200 for a result and for an error the protocol answers in
 * the body; 401, 413, 429, 500, 503 and 504 where the specification names
 * them.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRpcReply
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function __construct(
        public int $status,
        public array $body,
        public array $headers,
    ) {}

    /**
     * @param array<string, mixed> $result
     */
    public static function result(int|string|null $id, array $result): self
    {
        return new self(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], []);
    }

    public static function error(int|string|null $id, A2aRpcError $error): self
    {
        return new self($error->httpStatus, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()], $error->responseHeaders());
    }

    public function toResponse(): ResponseInterface
    {
        $response = new JsonResponse($this->body, $this->status, ['Content-Type' => A2aProtocol::CONTENT_TYPE, 'Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
