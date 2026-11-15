<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;

/**
 * An incremental `text/event-stream` parser (WHATWG HTML, "Parsing an event
 * stream"; spec 051 A2A-SSE6).
 *
 * Bytes are fed as they arrive, in pieces of any size. A line ends at CRLF,
 * LF or CR; a CR at the end of a piece waits for the next one, which may
 * start with the LF of the same CRLF. Lines are cut at those bytes only, so a
 * UTF-8 sequence split across two pieces is whole again before the line is
 * read. A UTF-8 byte order mark at the very start is dropped.
 *
 * Of the fields, `data` is collected (several `data` lines are joined with
 * LF) and `event` is kept; `id` and `retry` are read and not used, because
 * A2A gives them no meaning (nr-llm never reconnects with `Last-Event-ID`).
 * A line starting with a colon is a comment. A blank line dispatches the
 * event, unless its data is empty. A line or an event larger than the limit
 * makes the stream invalid: the parser never holds more than that.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aSseParser
{
    private string $buffer = '';

    private string $data = '';

    private bool $hasData = false;

    private string $event = '';

    private bool $started = false;

    private bool $pendingCr = false;

    public function __construct(
        private readonly int $maxEventBytes,
    ) {}

    /**
     * Feed the next bytes; returns the events they completed.
     *
     * @return list<array{event: string, data: string}>
     *
     * @throws A2aInvalidAgentResponseException when a line or an event exceeds the limit
     */
    public function feed(string $bytes): array
    {
        if ($this->pendingCr && $bytes !== '') {
            $this->pendingCr = false;
            if ($bytes[0] === "\n") {
                $bytes = substr($bytes, 1);
            }
        }

        $this->buffer .= $bytes;
        if (!$this->started) {
            if (strlen($this->buffer) < 3 && str_starts_with("\xEF\xBB\xBF", $this->buffer)) {
                // Possibly the first bytes of a byte order mark: wait.
                return [];
            }

            $this->started = true;
            if (str_starts_with($this->buffer, "\xEF\xBB\xBF")) {
                $this->buffer = substr($this->buffer, 3);
            }
        }

        $events = [];
        while (($end = strcspn($this->buffer, "\r\n")) < strlen($this->buffer)) {
            $line       = substr($this->buffer, 0, $end);
            $terminator = $this->buffer[$end];
            $skip       = 1;
            if ($terminator === "\r") {
                if ($end + 1 < strlen($this->buffer)) {
                    $skip = $this->buffer[$end + 1] === "\n" ? 2 : 1;
                } else {
                    $this->pendingCr = true;
                }
            }

            $this->buffer = substr($this->buffer, $end + $skip);
            $event        = $this->line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        if (strlen($this->buffer) > $this->maxEventBytes) {
            throw A2aInvalidAgentResponseException::invalidField('stream', sprintf('a line is longer than %d bytes', $this->maxEventBytes));
        }

        return $events;
    }

    /**
     * Whether the parser holds a part of an event that was not dispatched:
     * the stream ended in the middle of one.
     */
    public function hasPartialEvent(): bool
    {
        return $this->buffer !== '' || $this->hasData;
    }

    /**
     * @return array{event: string, data: string}|null
     */
    private function line(string $line): ?array
    {
        if ($line === '') {
            $event         = $this->hasData ? ['event' => $this->event !== '' ? $this->event : 'message', 'data' => $this->data] : null;
            $this->data    = '';
            $this->hasData = false;
            $this->event   = '';

            return $event;
        }

        if ($line[0] === ':') {
            return null;
        }

        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($field === 'data') {
            $this->data    = $this->hasData ? $this->data . "\n" . $value : $value;
            $this->hasData = true;
            if (strlen($this->data) > $this->maxEventBytes) {
                throw A2aInvalidAgentResponseException::invalidField('stream', sprintf('an event is larger than %d bytes', $this->maxEventBytes));
            }
        } elseif ($field === 'event') {
            $this->event = $value;
        }

        return null;
    }
}
