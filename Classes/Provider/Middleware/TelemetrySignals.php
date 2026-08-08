<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

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
 * zero fallback attempts, no configuration swap), which is the correct default
 * for any pipeline run that never touches those layers.
 *
 * @api
 */
final class TelemetrySignals
{
    public bool $cacheHit = false;

    public int $fallbackAttempts = 0;

    /**
     * Identifier of the configuration that ANSWERED, when that is not the one
     * the caller requested. null while nothing has swapped — which the
     * TelemetryMiddleware reads as "the requested configuration served it".
     */
    public ?string $servedConfigurationIdentifier = null;

    /** Provider of the configuration that answered; null with the identifier. */
    public ?string $servedProvider = null;

    /** Model of the configuration that answered; null with the identifier. */
    public ?string $servedModel = null;

    /**
     * CacheMiddleware calls this when it serves a stored response instead of
     * invoking the terminal.
     */
    public function recordCacheHit(): void
    {
        $this->cacheHit = true;
    }

    /**
     * FallbackMiddleware calls this with the sibling configuration whose call
     * RETURNED — never with one that was merely attempted.
     *
     * The distinction is the whole point of the signal: `fallbackAttempts`
     * already says how many siblings were dispatched, and the last of those is
     * usually the one that failed. Only a configuration that produced a
     * response is recorded as having served the run, so an exhausted chain
     * leaves this null and the row keeps naming the requested configuration.
     */
    public function recordServedBy(LlmConfiguration $configuration): void
    {
        $this->servedConfigurationIdentifier = $configuration->getIdentifier();
        $this->servedProvider                = $configuration->getProviderType();
        $this->servedModel                   = $configuration->getModelId();
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
