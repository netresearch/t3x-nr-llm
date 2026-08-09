<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerConfig;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerStoreInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The resilience state of every provider, as one readable answer (ADR-063).
 *
 * ADR-063 built two mechanisms and no way to look at them: health scores over
 * the telemetry window ({@see ProviderHealthServiceInterface}) and per-provider
 * circuit state in the `nrllm_circuit` cache
 * ({@see CircuitBreakerStoreInterface}). This joins them for the analytics
 * module — the numbers stay where they are computed, only the reading is here.
 *
 * Three things it is careful about, because each is a way to misread the page:
 *
 *  - **A score without its sample size and window says nothing.** A 0.9 over
 *    two calls and a 0.9 over two thousand are different facts, so the window
 *    is asked of the health service rather than restated here, and every row
 *    carries its sample count.
 *  - **No telemetry is not a score of zero.** A provider absent from the
 *    window gets `sampleCount = 0` and NULL score/success/latency, never the
 *    neutral 0.5 the advisor hands its own callers or a 0.0 that would read as
 *    "broken". The absence is structural, not a flag a template may forget.
 *  - **A switch that is off makes the scores decorative.** Both gates
 *    (`health.reorderFallback`, `circuitBreaker.enabled`) travel with the
 *    numbers so the view can say when they change nothing.
 */
final readonly class ProviderHealthReport
{
    public function __construct(
        private ProviderHealthServiceInterface $health,
        private CircuitBreakerStoreInterface $circuitStore,
        private ProviderRepository $providerRepository,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return array{
     *     windowSeconds: int,
     *     reorderFallback: bool,
     *     circuitBreakerEnabled: bool,
     *     providers: list<array{
     *         provider: string,
     *         sampleCount: int,
     *         score: float|null,
     *         successRate: float|null,
     *         avgLatencyMs: float|null,
     *         circuit: string,
     *         consecutiveFailures: int
     *     }>
     * }
     */
    public function readout(): array
    {
        $scores  = $this->health->all();
        $circuit = CircuitBreakerConfig::fromExtensionConfiguration($this->extensionConfiguration);
        $now     = time();

        $rows = [];
        foreach ($this->providerKeys($scores) as $provider) {
            $score = $scores[$provider] ?? null;
            $state = $this->circuitStore->load($provider);

            $rows[] = [
                'provider'            => $provider,
                'sampleCount'         => $score !== null ? $score->sampleCount : 0,
                'score'               => $score?->score,
                'successRate'         => $score?->successRate,
                'avgLatencyMs'        => $score?->avgLatencyMs,
                'circuit'             => $state->status($now, $circuit->cooldownSeconds)->value,
                'consecutiveFailures' => $state->consecutiveFailures,
            ];
        }

        return [
            'windowSeconds'         => $this->health->windowSeconds(),
            'reorderFallback'       => $this->health->reorderEnabled(),
            'circuitBreakerEnabled' => $circuit->enabled,
            'providers'             => $rows,
        ];
    }

    /**
     * Every provider worth a row, sorted, without duplicates.
     *
     * The union of two sets, because neither alone is the answer: the
     * configured active providers (so one that has not been called shows up as
     * "no data" instead of vanishing) and the providers the telemetry window
     * saw (so a specialized or ad-hoc caller's provider is not hidden just
     * because no provider record carries that adapter type). Health and circuit
     * state are both keyed by ADAPTER TYPE, not by provider record — two
     * provider records on the same adapter share one row, the same way they
     * share one circuit.
     *
     * @param array<string, ProviderHealthScore> $scores
     *
     * @return list<string>
     */
    private function providerKeys(array $scores): array
    {
        $keys = array_keys($scores);

        foreach ($this->providerRepository->findActive() as $provider) {
            $adapterType = $provider->getAdapterType();
            if ($adapterType !== '') {
                $keys[] = $adapterType;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
