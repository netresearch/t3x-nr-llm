<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use Closure;

/**
 * A response written line by line while the request is still working: one
 * JSON document per line (NDJSON), each flushed as it is written.
 *
 * The stream opens with its first line, not before. A caller that may still
 * answer with an ordinary response — a refusal, a redirect, a 422 — keeps that
 * option until it has something to send.
 *
 * Opening it sets `ignore_user_abort(true)`. Without it PHP stops at the first
 * write after the client has gone, and a continuation stopped there may be in
 * the middle of an approved write (ADR-276). A request that sends nothing until
 * it ends never notices the client is gone, so this keeps what the batch path
 * always did.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class NdjsonStream
{
    /**
     * A streamed response is held by the reverse proxy until a chunk clears its
     * flush threshold; padding every line past ~4 KB makes it flush at once, so
     * a line reaches the client when it is written instead of at the end.
     */
    public const MIN_LINE_BYTES = 4096;

    private bool $open = false;

    /**
     * @param (Closure(string): void)|null $sink where the lines go; null writes
     *                                           them to the client
     */
    public function __construct(
        private readonly ?Closure $sink = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Write one event. Encoded with JSON_INVALID_UTF8_SUBSTITUTE, so a malformed
     * byte in a step cannot break the stream, and padded with spaces, which
     * JSON.parse ignores.
     *
     * @param array<string, mixed> $event
     */
    public function line(array $event): void
    {
        if (!$this->open) {
            $this->open();
        }

        $json    = json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $padding = self::MIN_LINE_BYTES - strlen($json);
        $line    = $json . ($padding > 0 ? str_repeat(' ', $padding) : '') . "\n";

        if ($this->sink instanceof Closure) {
            ($this->sink)($line);

            return;
        }

        echo $line;
        flush();
    }

    private function open(): void
    {
        $this->open = true;
        ignore_user_abort(true);

        if ($this->sink instanceof Closure) {
            return;
        }

        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        if (!headers_sent()) {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
        }
    }
}
