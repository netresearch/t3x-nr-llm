<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

/**
 * Translates an MCP server's remote tool name into the local tool name
 * nr_llm registers it under (ADR-116).
 *
 * The same rule runs twice: the import validates a remote catalogue against
 * it and rejects what cannot be represented, the provider reconstructs the
 * local name when it resolves a call back to a server. Keeping it in a
 * dependency-free class makes that rule assertable without a database or a
 * live MCP server.
 *
 * The accepted character set is the one the function-calling APIs impose on
 * a tool name (letters, digits, underscore, hyphen, at most 64 characters);
 * MCP itself places no such bound on a remote name, so names outside it are
 * rejected rather than rewritten — a lossy rewrite could collide two remote
 * tools onto one local name, and the local name is also the key the
 * availability override is stored under.
 */
final readonly class McpToolNameMapper
{
    /**
     * The `D` modifier is load-bearing: without it `$` also matches before a
     * trailing newline, so a remote name ending in one would pass.
     */
    private const LOCAL_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/D';

    private const PREFIX = 'mcp_';

    /**
     * @return string|null Null when server identifier and remote name cannot
     *                     form a representable local tool name
     */
    public function localName(string $serverIdentifier, string $remoteName): ?string
    {
        $candidate = self::PREFIX . $serverIdentifier . '_' . $remoteName;

        if (preg_match(self::LOCAL_NAME_PATTERN, $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    public function group(string $serverIdentifier): string
    {
        return self::PREFIX . $serverIdentifier;
    }
}
