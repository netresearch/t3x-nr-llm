<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The outcome of one catalogue import for one MCP server (ADR-116).
 *
 * Carries the skip reasons as data so an operator can see which advertised tools
 * did not make it into the catalogue and why; a tool missing from the list
 * otherwise looks like a server that never offered it.
 */
final readonly class McpImportReport
{
    /**
     * @param list<string> $skipReasons One entry per rejected tool, plus the refusal reason when $refused.
     * @param bool         $refused     True when the import was declined before writing anything — an
     *                                  inert server, an unreachable endpoint. Distinct from an import
     *                                  that ran and wrote no tools, where the catalogue was still
     *                                  reconciled and $imported is 0.
     */
    public function __construct(
        public int $serverUid,
        public int $imported,
        public int $skipped,
        public int $orphaned,
        public array $skipReasons = [],
        public bool $refused = false,
    ) {}

    public static function refused(int $serverUid, string $reason): self
    {
        return new self(
            serverUid: $serverUid,
            imported: 0,
            skipped: 0,
            orphaned: 0,
            skipReasons: [$reason],
            refused: true,
        );
    }
}
