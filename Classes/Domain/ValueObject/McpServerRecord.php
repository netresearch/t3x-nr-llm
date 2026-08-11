<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;

/**
 * One operator-configured MCP server, read back from `tx_nrllm_mcp_server` (ADR-116).
 *
 * The immutable read model the repository hands to the import action and to the
 * registry. It carries the configuration only — no connection, no session — so a
 * caller cannot mistake holding a record for having reached the server.
 */
final readonly class McpServerRecord
{
    public function __construct(
        public int $uid,
        public int $pid,
        // Prefixes every imported tool name and the group `mcp_<identifier>`, so
        // a remote tool can never collide with a builtin or with another server.
        public string $identifier,
        public string $name,
        public string $description,
        public string $url,
        // nr-vault UUID, never a plaintext secret; '' = unauthenticated server.
        public string $authCredential,
        public string $authPlacement,
        public string $authHeaderName,
        // The ToolDataClass backed value the operator declared for everything this
        // server returns; '' means undeclared — see self::dataClassEnum().
        public string $dataClass,
        // The operator's approval declaration as stored. Kept raw and read
        // through self::approvalRequired(), which is where the fail-closed
        // reading of anything that is not a literal '0' lives.
        public string $requiresApproval,
        public bool $enabled,
        public string $importStatus,
        public string $importError,
        public int $lastImported,
        public int $toolCount,
        // The last round trip this installation completed against the server,
        // and how long it took (ADR-154). Written on every successful client
        // operation, not only on an import; 0 means never reached.
        public int $lastContact,
        public int $lastLatencyMs,
        public int $tstamp,
        public int $crdate,
    ) {}

    /**
     * The declared data class as a typed enum, or null when the operator has not
     * declared one (or declared a value this version does not know).
     *
     * Null makes the server inert: a caller must skip it, never substitute a
     * default. A remote server's egress sensitivity cannot be inferred, and
     * guessing one would let an undeclared server past the trust-zone gate
     * (ADR-094) at whatever class the guess picked.
     */
    public function dataClassEnum(): ?ToolDataClass
    {
        return ToolDataClass::tryFrom($this->dataClass);
    }

    /**
     * Whether a human must approve every tool of this server before the agent
     * loop executes it (ADR-134).
     *
     * Fail-closed by construction: only an explicit '0' — the operator having
     * unticked the box — means "no approval". A missing column, a NULL, a value
     * this version does not understand and an empty string all read as
     * "approval required", because the alternative is that an unreadable byte
     * silently lets an unattended remote write through. The stored value is a
     * tinyint, so the string comparison is exact rather than a coercion: '0' is
     * the only shape the check field ever writes for "off".
     */
    public function approvalRequired(): bool
    {
        return $this->requiresApproval !== '0';
    }
}
