<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The side effect a tool's execution has on the world (ADR-111).
 *
 * The agent queue is at-least-once: a run whose provider/tool call outlives its
 * lease is reaped and re-executed (ADR-104). That is safe only for operations
 * that can run again without harm. A tool declares its effect so the runtime
 * can (a) require a durable audit step for anything that writes and (b) refuse
 * to auto-retry a write that is not safe to repeat.
 *
 * Fail-closed default: a tool that does not declare an effect is treated as
 * {@see self::READ_ONLY} (via {@see \Netresearch\NrLlm\Service\Tool\ToolEffectInterface}).
 * That is correct for every tool shipped today — all are read-only — and a tool
 * that DOES write must opt in explicitly, so a new write can never be silently
 * treated as a repeatable read.
 */
enum ToolEffect: string
{
    /**
     * Observes only — no state changes. Re-running it is always safe, so a
     * reaped-and-requeued run may repeat it freely.
     */
    case READ_ONLY = 'read_only';

    /**
     * Writes, but re-running it converges to the same state (a set, an upsert
     * keyed by a stable identifier). Safe to auto-retry after a lease loss.
     */
    case IDEMPOTENT_WRITE = 'idempotent_write';

    /**
     * Writes with an effect that compounds on repeat (an append, a send, a
     * counter increment, a resource creation without a caller-supplied key).
     * Must NOT be auto-retried: a reaped run that may have completed the write
     * fails terminally rather than risking a double effect.
     */
    case NON_IDEMPOTENT_WRITE = 'non_idempotent_write';

    /**
     * Whether the effect changes state (anything other than read-only).
     */
    public function isWrite(): bool
    {
        return $this !== self::READ_ONLY;
    }

    /**
     * Whether an at-least-once runtime may safely re-execute this effect after a
     * lease loss. False for a non-idempotent write — repeating it could double
     * the effect.
     */
    public function isSafeToRetry(): bool
    {
        return $this !== self::NON_IDEMPOTENT_WRITE;
    }
}
