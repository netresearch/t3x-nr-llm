<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * Who holds the lease on an executing run segment (ADR-141).
 *
 * Every segment that can execute a tool claims the run before it runs one, so
 * the ADR-111 write fence and the ADR-104 heartbeat arm on all of them and not
 * only in a queue worker. The identity names the segment kind first, then the
 * process, so a lease left behind by a crash says which entry point abandoned
 * it: ``resume:web-01:4711``.
 *
 * Uniqueness comes from the process, not from this class: two segments never
 * execute the same run concurrently under one pid, and the ownership-guarded
 * writes in {@see \Netresearch\NrLlm\Service\Tool\AgentRunRepository} are what
 * actually decide a contested claim. The value is truncated to the 64 chars
 * ``tx_nrllm_agent_run.claimed_by`` stores, so a long hostname cannot silently
 * produce an identity that never matches itself again.
 *
 * @internal
 */
final readonly class ExecutionIdentity
{
    /** What ``tx_nrllm_agent_run.claimed_by`` can store. */
    private const MAX_LENGTH = 64;

    /**
     * A queue worker executing a claimed QUEUED run (ADR-102).
     */
    public static function worker(): string
    {
        return self::forSegment('worker');
    }

    /**
     * A synchronous run started from a request rather than the queue.
     */
    public static function interactive(): string
    {
        return self::forSegment('interactive');
    }

    /**
     * The continuation of a suspended run after an approval or a submitted
     * input. This is the segment a writing tool actually executes in: a write
     * suspends BEFORE it runs (ADR-134), so the first pass never reaches it.
     */
    public static function resume(): string
    {
        return self::forSegment('resume');
    }

    private static function forSegment(string $segment): string
    {
        $host = gethostname();

        return substr($segment . ':' . ($host !== false ? $host : 'unknown') . ':' . getmypid(), 0, self::MAX_LENGTH);
    }
}
