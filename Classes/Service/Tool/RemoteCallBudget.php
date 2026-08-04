<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * How many calls to a {@see RemoteToolInterface} tool one run segment may make.
 *
 * A builtin call is local and returns in milliseconds; the loop's iteration cap
 * is a sufficient bound on it. A remote call is neither. It crosses the network
 * three times — handshake, confirmation, invocation — and each leg may sit at
 * the transport timeout, while a backend user waits synchronously. Nothing else
 * bounds how many tool calls a model may emit in a single round, so without
 * this a model that loops on one MCP tool can hold a request open far longer
 * than the iteration cap suggests.
 *
 * MUTABLE AND SHORT-LIVED, DELIBERATELY. It is created inside a run method and
 * passed down, never injected. That is the whole point: the services around it
 * are container singletons, and on the queue path the worker process outlives
 * many runs, so any counter held on a service would bound the PROCESS and let
 * the second run inherit the first one's exhaustion.
 *
 * The bound is per run SEGMENT, not per run: a resumed run starts a fresh
 * budget. That is intentional — a resume only happens after a human approved
 * the pause, so the loop cannot spin through segments on its own.
 */
final class RemoteCallBudget
{
    /**
     * Generous enough that no plausible task hits it, small enough that a loop
     * on a slow server ends in minutes rather than at the request timeout.
     */
    public const DEFAULT_LIMIT = 20;

    private int $spent = 0;

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {}

    /**
     * Claim one call, or report that the budget is exhausted.
     */
    public function tryConsume(): bool
    {
        if ($this->spent >= $this->limit) {
            return false;
        }

        ++$this->spent;

        return true;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function spent(): int
    {
        return $this->spent;
    }
}
