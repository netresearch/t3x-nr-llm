<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

/**
 * Mutable scratchpad an inner middleware uses to signal an outer one within a
 * single pipeline run.
 *
 * The pipeline threads ONE immutable ProviderCallContext through every layer
 * and the `$next` callable only forwards the LlmConfiguration -- never a
 * context. An inner middleware therefore cannot hand a modified context back
 * out to a middleware that already captured the original. The one channel that
 * survives the unwind is a mutable object reachable from the shared context:
 * this class. ProviderCallContext carries exactly one instance (default-
 * constructed per call, see ProviderCallContext), so CacheMiddleware and
 * FallbackMiddleware can annotate it on the way in and TelemetryMiddleware --
 * the outermost layer -- reads the result on the way out.
 *
 * Deliberately NOT readonly: recording a signal is the whole point. It holds
 * only cross-cutting observability state, never payload, so it does not
 * weaken the "context carries no payload" rule of ADR-026.
 *
 * A fresh, un-annotated instance reads as "nothing happened" (no cache hit,
 * zero fallback attempts), which is the correct default for any pipeline run
 * that never touches those layers.
 */
final class TelemetrySignals
{
    public bool $cacheHit = false;

    public int $fallbackAttempts = 0;

    /**
     * CacheMiddleware calls this when it serves a stored response instead of
     * invoking the terminal.
     */
    public function recordCacheHit(): void
    {
        $this->cacheHit = true;
    }

    /**
     * FallbackMiddleware calls this once per fallback configuration it actually
     * dispatches (the primary attempt is not counted).
     */
    public function recordFallbackAttempt(): void
    {
        ++$this->fallbackAttempts;
    }
}
