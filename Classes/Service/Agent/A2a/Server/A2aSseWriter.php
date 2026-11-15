<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;

/**
 * The Server-Sent Events writer of the A2A server role (spec 051 A2A-SSE1,
 * A2A-SSE3, ADR-283).
 *
 * One event is one `data:` line holding one JSON-RPC response, followed by a
 * blank line. JSON encoding escapes every line break, so a frame is always a
 * single line. A frame larger than the limit is not written: the caller ends
 * the stream, and the client reads the task with `GetTask`. The writer holds
 * no more than the frame it is writing.
 *
 * A reverse proxy may hold a chunk until it clears a flush threshold; each
 * write is padded with an SSE comment line up to {@see self::MIN_WRITE_BYTES}
 * (a comment is ignored by every event-stream parser), so an event reaches the
 * client when it is written. `X-Accel-Buffering: no` asks nginx not to buffer.
 *
 * Unlike the backend's NDJSON stream, opening it leaves `ignore_user_abort`
 * as it is (false): a stream only reads, the worker executes the task, and a
 * client that goes away ends the script at the next write without touching
 * the task.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aSseWriter
{
    public const CONTENT_TYPE = A2aProtocol::EVENT_STREAM_CONTENT_TYPE;

    public const MIN_WRITE_BYTES = 4096;

    private bool $open = false;

    private float $lastWrite = 0.0;

    /**
     * @param int                          $maxFrameBytes the largest `data:` line
     * @param (Closure(string): void)|null $sink          where the bytes go; null writes them to the client
     * @param (Closure(): float)|null      $clock         seconds since the epoch
     */
    public function __construct(
        private readonly int $maxFrameBytes,
        private readonly ?Closure $sink = null,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Send the headers (once). Nothing else is written before the first event.
     */
    public function open(): void
    {
        if ($this->open) {
            return;
        }

        $this->open      = true;
        $this->lastWrite = $this->now();
        if ($this->sink instanceof Closure) {
            return;
        }

        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        // A buffer started without the removable flag cannot be ended, and
        // the level would not drop: stop there, and write into it.
        while (ob_get_level() > 0) {
            $flags = ob_get_status()['flags'] ?? 0;
            if (!is_int($flags) || ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0 || !ob_end_flush()) {
                break;
            }
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . self::CONTENT_TYPE . '; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }
    }

    /**
     * Write one JSON-RPC response as one event.
     *
     * @param array<string, mixed> $response
     *
     * @return bool false when the frame is larger than the limit and was not written
     */
    public function event(array $response): bool
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > $this->maxFrameBytes) {
            return false;
        }

        $this->write('data: ' . $json . "\n\n");

        return true;
    }

    /**
     * A comment line: keeps proxies and the client from timing out.
     */
    public function comment(string $text): void
    {
        $this->write(': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n");
    }

    /**
     * Seconds since the last write (or since the stream opened).
     */
    public function idleSeconds(): float
    {
        return $this->now() - $this->lastWrite;
    }

    private function write(string $bytes): void
    {
        $this->open();
        $padding = self::MIN_WRITE_BYTES - strlen($bytes);
        if ($padding > 3) {
            $bytes = ':' . str_repeat(' ', $padding - 3) . "\n\n" . $bytes;
        }

        $this->lastWrite = $this->now();
        if ($this->sink instanceof Closure) {
            ($this->sink)($bytes);

            return;
        }

        echo $bytes;
        flush();
    }

    private function now(): float
    {
        return $this->clock instanceof Closure ? ($this->clock)() : microtime(true);
    }
}
