<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Health;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;

/**
 * Provider health, derived from recent telemetry (ADR-063).
 *
 * A read-only advisor over the telemetry log. It never changes provider
 * selection on its own; callers decide whether to consult it. The one built-in
 * consumer is {@see \Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware},
 * which asks {@see self::reorder()} to prefer healthier providers — but only
 * when the operator opts in (see the method contract).
 */
interface ProviderHealthServiceInterface
{
    /**
     * Health of one provider over the recent window. A provider with no recent
     * telemetry is reported as {@see ProviderHealthScore::unknown()} (neutral),
     * never as unhealthy.
     */
    public function scoreFor(string $provider): ProviderHealthScore;

    /**
     * Health of every provider seen in the recent window, keyed by provider.
     *
     * @return array<string, ProviderHealthScore>
     */
    public function all(): array;

    /**
     * Length of the rolling window the scores reflect, in seconds.
     *
     * A score is only readable next to the window it was taken over — "80 %
     * over the last quarter hour" and "80 % over the last day" are different
     * statements. A readout must therefore be able to name the window rather
     * than restate it from a constant of its own, which would silently drift
     * from the one the scores were actually computed with.
     */
    public function windowSeconds(): int;

    /**
     * Whether the operator opted into the health-aware fallback reorder
     * (`health.reorderFallback`, default off).
     *
     * Exposed because a score that influences nothing is the likeliest
     * misreading of a health readout: a consumer that shows scores must be
     * able to say whether they currently change any decision. The default-off
     * semantics live here, with {@see self::reorder()}, so a second reader
     * cannot disagree about what an unset setting means.
     */
    public function reorderEnabled(): bool;

    /**
     * Return the fallback chain reordered by descending provider health, as a
     * HINT — a stable sort, so configurations whose providers are equally
     * healthy (or unknown) keep their configured order. This is the tie-break
     * the task calls for: it never drops a candidate, only reprioritises.
     *
     * Gated by the `health.reorderFallback` extension setting: OFF by default,
     * in which case the chain is returned untouched with no telemetry query, so
     * the configured fallback order stays the default and this stays
     * minimal-invasive. Chains shorter than two entries are returned as-is.
     */
    public function reorder(FallbackChain $chain): FallbackChain;
}
