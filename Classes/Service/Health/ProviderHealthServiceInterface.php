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
