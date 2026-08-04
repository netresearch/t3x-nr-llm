<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One page of an MCP `tools/list` response (ADR-116).
 *
 * Transport output, not persistence: the tool arrays are the server's payload as
 * received, so the import action is the single place that validates and names
 * them. Nothing here has passed a check yet.
 */
final readonly class McpToolsPage
{
    /**
     * @param list<array<string, mixed>> $tools      Advertised tools, verbatim from the response.
     * @param string|null                $nextCursor Cursor for the following request; null on the last page.
     */
    public function __construct(
        public array $tools,
        public ?string $nextCursor = null,
    ) {}
}
