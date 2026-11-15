<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use GuzzleHttp\Psr7\Request;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Agent\Exception\RunStartRefusedException;
use Netresearch\NrLlm\Service\Tool\Web\BoundedSinkStream;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchClientFactoryInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches the file of a `url` part, in the worker only (spec 051 A2A-FILE3,
 * A2A-SEC5, ADR-284).
 *
 * The URL passes the address guard again at use, with the file hosts of
 * `a2a.files` and the `a2a_external` egress group: an operator who removed
 * a host since the task was accepted, or a name that now resolves to a
 * private address, stops the fetch. The request is pinned to the addresses
 * the guard checked, follows no redirect, carries no credential (no
 * `Authorization`, no cookie; only `Accept` and `User-Agent`), and stops
 * reading one byte past the size limit whatever `Content-Length` says.
 *
 * A refusal that waiting would not change (the guard, a redirect, a status
 * that is not 2xx and not 408, 429 or 5xx, a body over the limit) is a
 * {@see RunStartRefusedException}; a transport failure is an
 * {@see A2aFileFetchFailedException}, which the worker's failure recovery
 * may retry.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileFetcher
{
    use ErrorMessageSanitizerTrait;

    public const TIMEOUT_SECONDS = 30;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const USER_AGENT = 'nr_llm-a2a-file-fetch (+https://github.com/netresearch/t3x-nr-llm)';

    private const RETRYABLE = [408, 429];

    public function __construct(
        private A2aUrlGuardFactory $guardFactory,
        private ExternalFetchClientFactoryInterface $clientFactory,
    ) {}

    /**
     * The bytes behind $url, at most $maxBytes of them.
     *
     * @throws RunStartRefusedException
     * @throws A2aFileFetchFailedException
     */
    public function fetch(string $url, string $mediaType, int $maxBytes): string
    {
        if (!$this->guardFactory->fileHostsConfigured()) {
            throw new RunStartRefusedException('no file hosts are configured; a url part is not fetched', 5446047939);
        }

        $target = $this->guardFactory->createForFiles()->check(A2aUrlGuardFactory::GROUP_EXTERNAL, $url);
        if (!$target->allowed) {
            throw new RunStartRefusedException('the file URL is refused: ' . $target->reason, 5048306568);
        }

        if ($target->resolvePin() !== null && !$this->clientFactory->supportsPinning()) {
            throw new RunStartRefusedException('the file URL cannot be fetched pinned to its checked addresses', 2483912745);
        }

        $request = new Request('GET', $target->url, ['Accept' => $mediaType, 'User-Agent' => self::USER_AGENT]);
        if (trim(strtolower($request->getUri()->getHost()), '[]') !== $target->host) {
            throw new RunStartRefusedException('the file URL is ambiguous', 6988653307);
        }

        $sink    = new BoundedSinkStream($maxBytes + 1);
        $options = [
            'allow_redirects' => false,
            'http_errors'     => false,
            'cookies'         => false,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout'         => self::TIMEOUT_SECONDS,
            'sink'            => $sink,
        ];
        $pin = $target->resolvePin();
        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        try {
            $response = $this->clientFactory->create(self::TIMEOUT_SECONDS)->send($request, $options);
        } catch (Throwable $exception) {
            if ($sink->isTruncated()) {
                throw new RunStartRefusedException(sprintf('the file holds more than %d bytes', $maxBytes), 0, $exception);
            }

            throw new A2aFileFetchFailedException('the file could not be fetched: ' . $this->sanitizeErrorMessage($exception->getMessage()), 0, $exception);
        }

        return $this->body($response, $sink, $maxBytes);
    }

    /**
     * @throws RunStartRefusedException
     * @throws A2aFileFetchFailedException
     */
    private function body(ResponseInterface $response, BoundedSinkStream $sink, int $maxBytes): string
    {
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new RunStartRefusedException('the file URL redirects; a redirect is not followed', 4027161631);
        }

        if (in_array($status, self::RETRYABLE, true) || $status >= 500) {
            throw new A2aFileFetchFailedException(sprintf('the file host answered %d', $status), 5406922472);
        }

        if ($status < 200 || $status >= 300) {
            throw new RunStartRefusedException(sprintf('the file host answered %d', $status), 8182794948);
        }

        $bytes = $sink->contents();
        if ($sink->isTruncated() || strlen($bytes) > $maxBytes) {
            throw new RunStartRefusedException(sprintf('the file holds more than %d bytes', $maxBytes), 6245811337);
        }

        return $bytes;
    }
}
