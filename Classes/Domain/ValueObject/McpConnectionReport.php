<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What one connection test learned about one MCP server (ADR-154).
 *
 * The answer to "is this thing alive", and nothing else. It carries no tool
 * list and no catalogue state, because the test that produces it performs the
 * handshake and stops — an operator must be able to ask whether a server
 * answers without also rewriting what its tools are.
 *
 * The three strings after the latency are written by the remote party. They
 * are clipped where the report is built and rendered escaped; nothing reads
 * them to make a decision.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class McpConnectionReport
{
    private function __construct(
        public bool $reachable,
        // Duration of the handshake round trip in milliseconds; 0 when the
        // server was never reached.
        public int $latencyMs,
        public string $protocolVersion,
        public string $serverName,
        public string $serverVersion,
        // The reason it failed, already bounded and control-stripped by
        // McpTransportException. Empty when the server answered.
        public string $error,
    ) {}

    public static function reached(
        int $latencyMs,
        string $protocolVersion,
        string $serverName,
        string $serverVersion,
    ): self {
        return new self(true, $latencyMs, $protocolVersion, $serverName, $serverVersion, '');
    }

    public static function unreachable(string $error): self
    {
        return new self(false, 0, '', '', '', $error);
    }
}
