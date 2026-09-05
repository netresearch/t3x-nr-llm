<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp;

use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Http\CancellableHttpClientInterface;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A client wearing both of nr-vault's hats, recording which send it was asked
 * for (ADR-190).
 *
 * {@see McpTestServer} implements PSR-18 alone, which is what most transport
 * cases want; this one also implements
 * {@see CancellableHttpClientInterface}, so the branch that chooses between the
 * two sends has something real to choose between. Named rather than anonymous
 * because the cases read `$client->calls`, and an intersection type has no
 * properties.
 */
final class RecordingCancellableClient implements CancellableHttpClientInterface, ClientInterface
{
    /** @var list<string> The sends this client was asked for, in order. */
    public array $calls = [];

    public function __construct(
        private readonly bool $supportsCancellation = true,
        private readonly bool $cancelMidFlight = false,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls[] = 'sendRequest';

        return $this->answer();
    }

    public function sendCancellable(RequestInterface $request, CancellationSignalInterface $signal): ResponseInterface
    {
        $this->calls[] = 'sendCancellable';

        // What nr-vault does between ticks of its event loop: ask, and tear the
        // transfer down when the answer is yes.
        if ($this->cancelMidFlight && $signal->isCancelled()) {
            throw new RequestCancelledException('the caller cancelled the request', 1788400001);
        }

        return $this->answer();
    }

    public function supportsCancellation(): bool
    {
        return $this->supportsCancellation;
    }

    private function answer(): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]));
        $stream->rewind();

        return (new Response())
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
