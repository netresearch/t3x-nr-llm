<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Transport;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body that is never at its end and never has a byte to give.
 */
final class A2aSilentBody implements StreamInterface
{
    public int $reads = 0;

    public bool $closed = false;

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach(): mixed
    {
        $this->closed = true;

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('not seekable', 1799100400);
    }

    public function rewind(): void
    {
        throw new RuntimeException('not seekable', 1799100401);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('not writable', 1799100402);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        ++$this->reads;

        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
