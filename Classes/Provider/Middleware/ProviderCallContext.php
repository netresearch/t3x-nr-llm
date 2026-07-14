<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Symfony\Component\Uid\Uuid;

/**
 * Immutable context threaded through a MiddlewarePipeline invocation.
 *
 * Carries what every middleware needs to know about the call without leaking
 * the operation-specific payload (messages, embeddings input, tool specs).
 * The payload stays captured in the terminal callable, which lets each
 * feature service keep its typed signature.
 */
final readonly class ProviderCallContext
{
    /**
     * @param array<string, mixed> $metadata         additional cross-cutting data
     *                                               (e.g. user id for budget checks,
     *                                               cache-key inputs, trace tags)
     * @param TelemetrySignals     $telemetrySignals mutable scratchpad an inner
     *                                               middleware uses to signal the
     *                                               outer TelemetryMiddleware within
     *                                               this run (cache hit, fallback
     *                                               attempts). Default-constructed
     *                                               per call so every pipeline run
     *                                               has its own; the `new` default
     *                                               is evaluated fresh on each call,
     *                                               never shared across contexts.
     */
    public function __construct(
        public ProviderOperation $operation,
        public string $correlationId,
        public array $metadata = [],
        public TelemetrySignals $telemetrySignals = new TelemetrySignals(),
    ) {}

    /**
     * Create a context with an auto-generated UUID v4 correlation id.
     *
     * Callers that already hold a correlation id (e.g. propagated from an
     * upstream trace / request id) should use the regular constructor instead
     * of this factory so the incoming id survives end-to-end.
     *
     * @param array<string, mixed> $metadata
     */
    public static function for(ProviderOperation $operation, array $metadata = []): self
    {
        return new self(
            operation: $operation,
            correlationId: Uuid::v4()->toRfc4122(),
            metadata: $metadata,
        );
    }

    /**
     * Return a new context with the given metadata merged on top of the current
     * map. Useful for middleware that wants to annotate downstream handlers
     * (cache hit/miss, budget remaining, retry attempt counter, etc.) without
     * mutating state.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            operation: $this->operation,
            correlationId: $this->correlationId,
            metadata: [...$this->metadata, ...$metadata],
            // Carry the SAME signal sink forward: a context re-derived mid-run
            // (e.g. to annotate downstream metadata) must not silently drop the
            // cache-hit / fallback signals collected against the original.
            telemetrySignals: $this->telemetrySignals,
        );
    }
}
