<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What one remote tool call came back with (ADR-161).
 *
 * MCP reports a tool-level failure INSIDE a successful JSON-RPC response, as
 * `isError` on the result. That is the ordinary way a working server says "the
 * tool failed" — the file was missing, the query was rejected — and it is far
 * more common than the server being unreachable.
 *
 * It is carried as a flag rather than folded into the text because the flag is
 * what the persisted tool step stores and what a reader counts. A failure
 * returned as prose is audited as a successful step whose content happens to
 * read like an error, and "how often does this server fail" then has no answer
 * that is not a string search.
 *
 * @internal
 */
final readonly class McpCallOutcome
{
    /**
     * @param string $text    the readable answer, including the note about any
     *                        content blocks this client could not carry
     * @param bool   $isError whether the SERVER flagged the call as failed
     */
    public function __construct(
        public string $text,
        public bool $isError,
    ) {}
}
