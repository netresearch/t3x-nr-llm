<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Health;

/**
 * A provider's recent health, derived from the telemetry log (ADR-063).
 *
 * `successRate` is the share of successful runs in the sampled window (0.0–1.0);
 * `avgLatencyMs` the mean end-to-end latency of the runs the provider served
 * ITSELF. `score` is a single comparable 0.0–1.0 number combining both — success
 * rate dominates, latency is a mild secondary penalty — so two providers can be
 * ordered by health with one comparison.
 *
 * A provider with no telemetry in the window is "unknown": `sampleCount === 0`
 * and a neutral `score` of {@see self::NEUTRAL_SCORE}, so an absence of data
 * never reads as unhealthy (it must not push a provider down the fallback order
 * just because it has not been called yet).
 *
 * `avgLatencyMs` is NULL for a second, narrower absence: the provider WAS called
 * in the window, but a fallback rescued every one of those runs, so not one of
 * them is a measurement of this provider's own latency. NULL is not 0.0 —
 * "never answered on its own" must not read as "answered in under a
 * millisecond".
 */
final readonly class ProviderHealthScore
{
    /**
     * Neutral score for a provider with no samples — ranks between healthy and
     * degraded so "unknown" neither jumps the queue nor sinks.
     */
    public const NEUTRAL_SCORE = 0.5;

    /**
     * Latency at/above which the latency component is fully penalised. Beyond a
     * few seconds of mean latency the exact figure no longer matters for
     * ordering — the provider is simply slow.
     */
    private const LATENCY_CEILING_MS = 5000.0;

    public function __construct(
        public string $provider,
        public int $sampleCount,
        public float $successRate,
        public ?float $avgLatencyMs,
        public float $score,
    ) {}

    /**
     * A provider with no telemetry in the window.
     */
    public static function unknown(string $provider): self
    {
        return new self($provider, 0, 0.0, null, self::NEUTRAL_SCORE);
    }

    /**
     * Compose a score from a sampled window.
     *
     * score = 0.8 · successRate + 0.2 · (1 − normalisedLatency)
     *
     * Success rate is weighted four times the latency term: a provider that
     * answers slowly is preferable to one that fails. Latency is normalised
     * against {@see self::LATENCY_CEILING_MS} and clamped to [0, 1].
     *
     * A NULL `$avgLatencyMs` (no self-served run to measure) carries no penalty,
     * exactly as before it could be told apart from a measured 0.0: an unknown
     * is not evidence of slowness, and the failure it comes from is already
     * priced into `$successCount`, which such a run never increments.
     */
    public static function fromSamples(string $provider, int $sampleCount, int $successCount, ?float $avgLatencyMs): self
    {
        if ($sampleCount <= 0) {
            return self::unknown($provider);
        }

        $successRate = $successCount / $sampleCount;

        $latencyPenalty = min(1.0, max(0.0, $avgLatencyMs ?? 0.0) / self::LATENCY_CEILING_MS);
        $score          = (0.8 * $successRate) + (0.2 * (1.0 - $latencyPenalty));

        return new self(
            $provider,
            $sampleCount,
            $successRate,
            $avgLatencyMs,
            $score,
        );
    }
}
