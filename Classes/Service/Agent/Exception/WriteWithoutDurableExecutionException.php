<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use RuntimeException;

/**
 * A side-effecting tool was about to run outside a leased execution context
 * (ADR-141).
 *
 * The write fence (ADR-111) stamps a tool's effect on the run row under an
 * ownership guard BEFORE the tool executes, so a crash mid-write cannot be
 * retried into a second side effect. That stamp needs two things: a persisted
 * run, and a lease this segment owns. A segment holding neither can still be
 * audited, but it cannot be fenced — and an unfenceable write is refused rather
 * than waved through.
 *
 * This is fail-closed by design and never retryable: the run stops before the
 * side effect, so nothing was written. Reaching it means an execution path
 * called a tool without claiming its run first, which is a wiring defect in that
 * path, not a condition an operator can clear.
 */
final class WriteWithoutDurableExecutionException extends RuntimeException
{
    public static function forTool(string $runUuid, string $toolName): self
    {
        return new self(
            sprintf(
                'Tool "%s" declares a side effect but run %s holds no execution lease; the write is refused because it cannot be fenced.',
                $toolName,
                $runUuid !== '' ? $runUuid : '(unpersisted)',
            ),
            1786665601,
        );
    }
}
