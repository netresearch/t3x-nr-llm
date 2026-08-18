<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Counts the HTTP attempts a provider adapter had to repeat (ADR-174).
 *
 * The retry loop lives in {@see \Netresearch\NrLlm\Provider\AbstractProvider::sendRequest()},
 * which is reached from the pipeline TERMINAL and never sees the call context —
 * so the scratchpad on {@see \Netresearch\NrLlm\Provider\Middleware\TelemetrySignals}
 * is out of reach from where the number is produced. A process-wide counter is
 * the one channel both ends can hold: the adapters increment it, and the two
 * telemetry write sites difference it around the run they record — one snapshot
 * difference where that run is synchronous, a sum of per-segment differences
 * where it suspends. See below; the distinction is not cosmetic.
 *
 * MONOTONIC, never reset. A reset would be wrong under nesting — a tool loop
 * runs pipeline runs inside a pipeline run, and the inner one resetting would
 * make the outer row report only the retries that happened after it. Taking a
 * difference makes nesting count correctly at both levels: retries consumed
 * DURING this run, inner runs included.
 *
 * A difference is only sound around a run that holds the stack from start to
 * finish. PHP executes one provider call at a time, so around a SYNCHRONOUS run
 * — the middleware pipeline {@see \Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware}
 * wraps, which streaming does not go through — one before/after difference
 * cannot pick up another call's retries.
 *
 * A GENERATOR can, and the streaming path is one:
 * {@see \Netresearch\NrLlm\Service\Streaming\StreamingDispatcher::drain()}
 * suspends at every yield and the consumer runs there, free to make provider
 * calls of its own. It therefore sums a difference per resumption segment
 * instead of taking one across the whole drain. Any future write site that
 * suspends has to do the same; a single difference around a suspending run
 * attributes the consumer's retries to it.
 *
 * @internal
 */
final class ProviderRetryCounter implements SingletonInterface
{
    private int $retries = 0;

    /**
     * Called once per attempt that is about to be REPEATED — not once per
     * attempt. A single-attempt call that fails outright consumed no retry.
     */
    public function recordRetry(): void
    {
        ++$this->retries;
    }

    /**
     * Retries recorded since this process started. Only differences of this
     * value mean anything; the absolute figure is an implementation detail.
     */
    public function total(): int
    {
        return $this->retries;
    }
}
