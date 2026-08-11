<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;

/**
 * Records that a server answered, and how fast (ADR-154).
 *
 * The seam exists so {@see McpClient} — the one place that knows an operation
 * finished — can report liveness without holding a database connection, and so
 * a test can observe the reporting without a database. The single production
 * implementation is {@see McpHealthRecorder}.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface McpHealthRecorderInterface
{
    /**
     * A round trip against this server succeeded just now.
     *
     * MUST NOT throw. A failed health write is a lost observation, never a
     * failed tool call.
     *
     * @param int $latencyMs duration of the round trip that is being reported
     */
    public function recordContact(McpServerRecord $server, int $latencyMs): void;
}
