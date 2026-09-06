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

    /** How many cancellable sends have gone through, so a case can pick one. */
    private int $cancellableSends = 0;

    /**
     * @param bool     $cancelMidFlight         tear the transfer down on ANY cancellable send whose signal says so
     * @param int|null $cancelOnCancellableSend tear it down on exactly this cancellable send (1-based), and no other.
     *                                          One MCP tool call is three round trips -- `initialize`, the
     *                                          `notifications/initialized` that confirms it, then `tools/call` --
     *                                          so a case that means "cancelled during the tool call" has to say 3.
     *                                          Cancelling on the first proves the handshake aborts, which is a
     *                                          different claim.
     */
    public function __construct(
        private readonly bool $supportsCancellation = true,
        private readonly bool $cancelMidFlight = false,
        private readonly ?int $cancelOnCancellableSend = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls[] = 'sendRequest';

        return $this->answer();
    }

    public function sendCancellable(RequestInterface $request, CancellationSignalInterface $signal): ResponseInterface
    {
        $this->calls[] = 'sendCancellable';
        ++$this->cancellableSends;

        // What nr-vault does between ticks of its event loop: ask, and tear the
        // transfer down when the answer is yes.
        $selected = $this->cancelOnCancellableSend === null
            || $this->cancelOnCancellableSend === $this->cancellableSends;

        if (($this->cancelMidFlight || $this->cancelOnCancellableSend !== null) && $selected && $signal->isCancelled()) {
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
