<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

/**
 * What one model's calls of one operation actually took and cost (ADR-256).
 *
 * The population is the calls the model answered ITSELF and for real: a
 * success on the first attempt (no fallback), served by the provider rather
 * than from the answer cache. A cache hit measures the cache, and a rescued run
 * measures the sibling that answered, so neither says anything about this model.
 *
 * Two counts, because the cost has a population of its own: a call is costed
 * only where a price was known (ADR-174), so a model can have enough calls to
 * trust its latency and too few priced ones to trust its cost.
 *
 * A figure below {@see self::MIN_SAMPLES} is returned as null — the same
 * absence a model with no history at all has. One slow call is an anecdote, and
 * an anecdote must not reorder a catalogue.
 *
 * @internal
 */
final readonly class ModelCallStats
{
    /**
     * Calls a figure needs before it ranks anything. Judgement, like the
     * ADR-142 weights: high enough that one outlier does not decide, low
     * enough that a model used a few times a day gets a figure within the week.
     */
    public const MIN_SAMPLES = 5;

    public function __construct(
        public int $sampleCount,
        public ?float $avgLatencyMs,
        public int $costedCount,
        public ?float $avgCostPerCall,
    ) {}

    /**
     * Mean latency in milliseconds, or null where too few calls were measured.
     */
    public function latencyMs(): ?float
    {
        return $this->sampleCount >= self::MIN_SAMPLES ? $this->avgLatencyMs : null;
    }

    /**
     * Mean cost per call in dollars, or null where too few calls were priced.
     */
    public function costPerCall(): ?float
    {
        return $this->costedCount >= self::MIN_SAMPLES ? $this->avgCostPerCall : null;
    }

    /**
     * The lookup key: adapter type, model id and operation, the three columns
     * a telemetry row names its serving model by.
     */
    public static function key(string $provider, string $model, string $operation): string
    {
        return $provider . "\0" . $model . "\0" . $operation;
    }
}
