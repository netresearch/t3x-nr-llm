<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Exception;

use RuntimeException;

/**
 * A call to an MCP server did not produce a usable result (ADR-116).
 *
 * One type for every way the conversation can fail — refused host, transport
 * error, non-2xx status, unparseable body, JSON-RPC error object, an operation
 * budget that ran out (ADR-170) — because every caller does the same thing with
 * it: record it against the server and stop. The distinction that matters to an
 * operator is the message, which names the server, and that survives into
 * `import_error`. Each factory below carries its own code, so a reader who
 * needs the kind rather than the sentence has it without parsing prose.
 *
 * The message is built here rather than at the call site so a server's own
 * response text can never be pasted into it unbounded: a remote party writes
 * that text, and it ends up in a backend view and a log line.
 */
final class McpTransportException extends RuntimeException
{
    /**
     * How much of a remote party's text is worth keeping. Enough to identify
     * the fault, short enough that a hostile server cannot flood the log.
     */
    private const REMOTE_TEXT_LIMIT = 200;

    public static function forRefusedHost(string $identifier, string $host): self
    {
        return new self(
            sprintf('MCP server "%s" points at host "%s", which outbound requests are not allowed to reach.', $identifier, $host),
            1799990210,
        );
    }

    public static function forTransportFailure(string $identifier, string $reason): self
    {
        return new self(
            sprintf('MCP server "%s" could not be reached: %s', $identifier, self::clip($reason)),
            1799990211,
        );
    }

    public static function forStatus(string $identifier, int $status): self
    {
        return new self(
            sprintf('MCP server "%s" answered with HTTP status %d.', $identifier, $status),
            1799990212,
        );
    }

    public static function forMalformedResponse(string $identifier, string $detail): self
    {
        return new self(
            sprintf('MCP server "%s" sent a response that is not a JSON-RPC result: %s', $identifier, self::clip($detail)),
            1799990213,
        );
    }

    public static function forRpcError(string $identifier, int $code, string $message): self
    {
        return new self(
            sprintf('MCP server "%s" refused the call with JSON-RPC error %d: %s', $identifier, $code, self::clip($message)),
            1799990214,
        );
    }

    public static function forUnsupportedContentType(string $identifier, string $contentType): self
    {
        return new self(
            sprintf(
                'MCP server "%s" answered with content type "%s"; this client reads a JSON response, plain or framed as a single event-stream message, and nothing else.',
                $identifier,
                self::clip($contentType),
            ),
            1799990215,
        );
    }

    /**
     * The operation spent its whole budget before this leg could be sent
     * (ADR-170).
     *
     * Worded so the log tells the two apart at a glance: nothing was asked of
     * the server here, and the number that ran out is one this installation
     * chose and can raise. Every other factory above describes a far side that
     * answered badly or not at all.
     *
     * It is NOT a cancellation, and says so. Cancelling an in-flight call
     * remains unimplemented (issue #774, ADR-161) — this refuses a request that
     * has not started.
     */
    public static function forExhaustedDeadline(string $identifier, int $totalSeconds): self
    {
        return new self(
            sprintf(
                'The %d-second operation budget for MCP server "%s" ran out before this request was sent, '
                . 'so the server was not asked. This is the budget this installation sets, not a server that did not answer.',
                $totalSeconds,
                $identifier,
            ),
            1799990217,
        );
    }

    public static function forMissingCredential(string $identifier): self
    {
        return new self(
            sprintf('MCP server "%s" declares a credential that the vault could not resolve.', $identifier),
            1799990216,
        );
    }

    /**
     * Bound text that a remote party controls, and strip the control
     * characters that would otherwise forge line breaks in a log record.
     */
    private static function clip(string $text): string
    {
        $clean = preg_replace('/[[:cntrl:]]+/u', ' ', $text) ?? '';
        $clean = trim($clean);

        if (mb_strlen($clean) <= self::REMOTE_TEXT_LIMIT) {
            return $clean;
        }

        return mb_substr($clean, 0, self::REMOTE_TEXT_LIMIT) . '…';
    }
}
