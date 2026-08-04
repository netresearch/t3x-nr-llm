<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One imported MCP tool, read back from `tx_nrllm_mcp_tool` (ADR-116).
 *
 * The catalogue row is written only by the import action; at run time this is a
 * read model. It keeps the two names apart: {@see self::$toolName} is the local
 * name the gate, the tool state and the model all see, {@see self::$remoteName}
 * is what goes on the wire in `tools/call`.
 */
final readonly class McpToolRecord
{
    public function __construct(
        public int $uid,
        public int $pid,
        public int $server,
        public string $toolName,
        public string $remoteName,
        public string $description,
        // Normalised JSON-Schema parameter object as stored; decode via
        // self::inputSchemaArray().
        public string $inputSchema,
        // The server's `annotations` block, kept verbatim for display. No
        // resolver reads it: a remote server must not be able to influence
        // authorisation by what it writes here.
        public string $remoteAnnotations,
        // Present in an earlier import, absent from the latest. Kept rather than
        // deleted so an operator sees what disappeared from the server.
        public bool $orphaned,
        public int $tstamp,
        public int $crdate,
    ) {}

    /**
     * The stored parameter schema, or null when it is undecodable or not a JSON
     * object. Callers build a ToolSpec from this, and a schema that is not an
     * object cannot become one.
     *
     * @return array<string, mixed>|null
     */
    public function inputSchemaArray(): ?array
    {
        // A top-level JSON array decodes to a PHP array just like an object
        // does, and `[]` and `{}` are indistinguishable after decoding — so the
        // object-ness is decided on the raw text.
        if (!str_starts_with(ltrim($this->inputSchema), '{')) {
            return null;
        }

        $decoded = json_decode($this->inputSchema, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
