<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that stops the download at a byte limit (ADR-202).
 *
 * Guzzle's curl handler writes every received chunk through the sink and
 * hands curl the number of bytes the sink accepted. Once the limit is
 * reached this stream accepts only the part that still fits and reports the
 * shorter count; curl treats a short write as an error and aborts the
 * transfer. That is what bounds the bytes DOWNLOADED, not only the bytes
 * kept: a server cannot push more than the limit (plus one network chunk)
 * into this process, whatever its Content-Length says.
 */
final class BoundedSinkStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    private bool $truncated = false;

    public function __construct(
        private readonly int $limit,
    ) {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    }

    public function write($string): int
    {
        $room = $this->limit - $this->written;
        if (strlen($string) > $room) {
            $this->truncated = true;
            $string          = substr($string, 0, max(0, $room));
        }

        if ($string === '') {
            return 0;
        }

        $accepted = $this->stream->write($string);
        $this->written += $accepted;

        return $accepted;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Everything accepted so far, regardless of the read position.
     */
    public function contents(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }
}
