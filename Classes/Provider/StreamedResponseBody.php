<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Closure;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * The body of a streamed provider response, with a transfer failure reported
 * as a {@see ProviderConnectionException}.
 *
 * nr-vault's streaming body reads from the wire, so a timeout or a dropped
 * connection after the response head surfaces from `read()` — as a
 * `VaultException` — rather than from the send. The blocking send reported the
 * same failure as a connection error, which the fallback chain answers with the
 * next configuration; an unknown exception class ends the stream instead. A
 * cancellation is the caller's own decision and passes through unchanged.
 *
 * @internal
 */
final readonly class StreamedResponseBody implements StreamInterface
{
    /**
     * @param Closure(string): string $sanitize redacts credentials from the message of a transfer failure
     */
    public function __construct(
        private StreamInterface $inner,
        private Closure $sanitize,
    ) {}

    public function __toString(): string
    {
        return $this->guard(fn (): string => $this->inner->__toString());
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->guard(fn (): string => $this->inner->read($length));
    }

    public function getContents(): string
    {
        return $this->guard(fn (): string => $this->inner->getContents());
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    /**
     * @param Closure(): string $read
     */
    private function guard(Closure $read): string
    {
        try {
            return $read();
        } catch (RequestCancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ProviderConnectionException(
                'Streaming response from provider failed: ' . ($this->sanitize)($e->getMessage()),
                1793900101,
                $e,
            );
        }
    }
}
