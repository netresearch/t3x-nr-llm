<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;
use Netresearch\NrVault\Http\CancellationSignalInterface;

/**
 * "Has this run been cancelled?", asked from inside a transfer that is still on
 * the wire (#774, ADR-103).
 *
 * nr-vault's `sendCancellable()` polls this on every tick of its event loop --
 * up to ten times a second per in-flight request -- and tears the socket down
 * the moment it answers true. The question is the same one
 * {@see AgentRunExecutor} asks at every step boundary: one indexed read of the
 * run row, status `CANCELLED`. Deliberately the same read, so "cancelled" has
 * one definition rather than a second one that can drift from it.
 *
 * Three properties the interface's contract and the poll rate force:
 *
 * - **Throttled.** Ten database reads per second, per in-flight request, to
 *   observe a state an operator changes by hand is a poor trade. The row is
 *   read at most once per {@see self::MIN_INTERVAL_NANOSECONDS}; a cancel
 *   therefore lands within about a second, which is the difference between
 *   aborting a call and waiting out a 45-second timeout.
 * - **Fail-soft.** `isCancelled()` MUST NOT throw -- an exception here would
 *   escape mid-transfer, after the credential has already gone out. It cannot:
 *   {@see AgentRunPersister::findRun()} catches `Throwable` and answers null,
 *   which this reads as "not cancelled". A store hiccup must never fabricate a
 *   cancellation either, which is the rule the executor's own probe follows and
 *   the reason nothing is re-thrown or re-tried here.
 * - **Monotonic.** Once the answer is true it stays true without reading again.
 *   A run that leaves CANCELLED cannot un-cancel a transfer that has already
 *   been torn down, and re-reading would only add a way to answer differently
 *   twice about the same transfer.
 *
 * The first call always reads. nr-vault asks BEFORE it touches the secret, and
 * refuses there with its own audit action, so a signal that opened its window
 * at construction time would answer false on entry and let a credential go out
 * for a run that was already cancelled.
 *
 * One instance per tool call. Monotonicity within a single send is what
 * matters; across calls the step-boundary probe ends the run first.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class AgentRunCancellationSignal implements CancellationSignalInterface
{
    /**
     * One second, in the unit {@see McpClockInterface} speaks.
     *
     * The bound on how stale the answer may be, not a delay: an operator's
     * cancel is honoured within about a second of being recorded.
     */
    public const MIN_INTERVAL_NANOSECONDS = 1_000_000_000;

    private bool $cancelled = false;

    private ?int $lastReadAt = null;

    public function __construct(
        private readonly AgentRunPersister $persister,
        private readonly McpClockInterface $clock,
        private readonly string $runUuid,
    ) {}

    public function isCancelled(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        $now = $this->clock->monotonicNanoseconds();
        if ($this->lastReadAt !== null && ($now - $this->lastReadAt) < self::MIN_INTERVAL_NANOSECONDS) {
            return false;
        }

        $this->lastReadAt = $now;
        // The exact probe AgentRunExecutor runs at every step boundary, and no
        // second definition of "cancelled" beside it.
        $this->cancelled = $this->persister->findRun($this->runUuid)?->statusEnum() === AgentRunStatus::CANCELLED;

        return $this->cancelled;
    }
}
