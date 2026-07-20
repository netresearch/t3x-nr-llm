<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerConfig;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitBreakerStoreInterface;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitState;
use Netresearch\NrLlm\Provider\CircuitBreaker\CircuitStatus;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Per-provider circuit breaker (ADR-063).
 *
 * Tracks consecutive failing calls per provider and, once a threshold is
 * crossed, "opens" the circuit for a cooldown window: subsequent calls to that
 * provider fail fast with a {@see CircuitOpenException} instead of waiting on a
 * connection timeout. After the cooldown a single probe is allowed (half-open);
 * a success closes the circuit, a failure re-opens it.
 *
 * Pipeline placement — INNERMOST, priority 20 (just above the terminal):
 *
 *   TelemetryMiddleware      <-- 110  observes every run
 *     CacheMiddleware        <-- 100  short-circuits on hit
 *       BudgetMiddleware     <--  75  pre-flight denial
 *         FallbackMiddleware <--  50  swaps configuration on retryable failure
 *           UsageMiddleware  <--  25  records the served call
 *             CircuitBreaker <--  20  guards the actual provider call  (THIS)
 *               <terminal>
 *
 * Two placement invariants, both load-bearing:
 *
 *  1. INSIDE FallbackMiddleware. An open circuit throws
 *     {@see CircuitOpenException}, which FallbackMiddleware treats as retryable.
 *     Because the circuit sits *inside* the fallback loop, that exception is
 *     raised from within `$next($context)` on each attempt, so Fallback
 *     catches it and advances to the next configuration/provider. Placing the
 *     breaker OUTSIDE Fallback (e.g. between Budget and Fallback) would instead
 *     make an open primary circuit abort the whole call before Fallback ever
 *     ran — the opposite of the intent. This is why the breaker is inner, not
 *     the "between 75 and 50" slot a first sketch suggests.
 *
 *  2. INSIDE UsageMiddleware. The breaker measures the *pure* provider call:
 *     usage bookkeeping (which only runs on success and does its own error
 *     handling) never contaminates the health signal, and a genuine provider
 *     success closes the circuit before any post-processing.
 *
 * What trips the circuit: the same failure classes FallbackMiddleware considers
 * retryable — {@see ProviderConnectionException} (network / timeout / 5xx /
 * retries exhausted) and a 429 {@see ProviderResponseException} (rate limit).
 * Client errors (4xx other than 429), misconfiguration and unsupported-feature
 * errors mean the provider answered — they are left out of the health signal
 * entirely (neither trip nor reset).
 *
 * State lives in the `nrllm_circuit` cache via
 * {@see CircuitBreakerStoreInterface}; see ADR-063 for the storage rationale
 * (transient, shared across workers, self-decaying, no schema).
 *
 * Streaming stays out of the pipeline (ADR-026), so streamed calls are never
 * guarded here — consistent with FallbackMiddleware.
 *
 * Disable via the `circuitBreaker.enabled` extension setting (default ON). When
 * disabled the middleware is a verbatim pass-through.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 20])]
final readonly class CircuitBreakerMiddleware implements ProviderMiddlewareInterface
{
    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const DEFAULT_COOLDOWN_SECONDS  = 30;

    public function __construct(
        private CircuitBreakerStoreInterface $store,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     *
     * @throws CircuitOpenException when the provider's circuit is open
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        $config = $this->config();
        if (!$config->enabled) {
            return $next($context);
        }

        $provider = $this->circuitKey($context);
        if ($provider === '') {
            // Nothing stable to key a circuit on — do not guard.
            return $next($context);
        }

        $cooldown = $config->cooldownSeconds;
        $now      = time();
        $state    = $this->store->load($provider);

        $status        = $state->status($now, $cooldown);
        $halfOpenProbe = $status === CircuitStatus::HalfOpen;
        if ($status === CircuitStatus::Open) {
            // Fail fast so FallbackMiddleware can try the next provider.
            throw new CircuitOpenException($provider, $state->secondsUntilHalfOpen($now, $cooldown));
        }
        if ($halfOpenProbe) {
            // Cooldown elapsed. Reserve the single probe by refreshing the open
            // window up front, so any concurrent caller keeps failing fast while
            // this probe is in flight (a pragmatic single-probe gate without
            // needing an atomic compare-and-set on the cache backend).
            $this->store->save(
                $provider,
                new CircuitState($state->consecutiveFailures, $now),
                $this->stateLifetime($cooldown),
            );
        }

        try {
            $result = $next($context);
        } catch (Throwable $e) {
            if ($this->isTrippingFailure($e)) {
                $this->recordFailure($provider, $state, $now, $config);
            } elseif ($halfOpenProbe) {
                // A non-tripping failure means the provider RESPONDED (a client
                // 4xx / misconfiguration), so it is not a health signal. On a
                // half-open probe the reservation above already moved the open
                // window to now; restore the pre-probe state so the circuit
                // re-derives to half-open (a further probe stays due) instead of
                // re-arming Open for a full cooldown — otherwise recurring
                // non-tripping probes would starve the recovery path.
                $this->store->save($provider, $state, $this->stateLifetime($cooldown));
            }

            // Non-tripping failures on a closed circuit leave it untouched.
            throw $e;
        }

        // Success closes the circuit. Skip the write on the hot path when the
        // circuit was already fully closed with no failure streak.
        if (!$state->isPristine()) {
            $this->store->save($provider, CircuitState::closed(), $this->stateLifetime($cooldown));
        }

        return $result;
    }

    private function recordFailure(string $provider, CircuitState $state, int $now, CircuitBreakerConfig $config): void
    {
        // Non-atomic counter — best-effort, the same posture ADR-063 accepts for
        // the half-open probe gate. $state was loaded at the top of handle(), so
        // concurrent failures each read the same pre-failure count and write
        // count+1, losing increments; under heavy concurrent failure the breaker
        // trips a little LATE (the count still climbs ~one per cooldown window)
        // rather than never. The cache backend offers no portable atomic
        // increment; a strict count would need \TYPO3\CMS\Core\Locking\LockFactory
        // around this read-modify-write (hot-path contention) — deferred.
        $failures = $state->consecutiveFailures + 1;

        // Open when the threshold is reached, or keep it open when a half-open
        // probe (openedAt already set) just failed.
        $openedAt = ($failures >= $config->failureThreshold || $state->openedAt !== null) ? $now : null;

        $this->store->save(
            $provider,
            new CircuitState($failures, $openedAt),
            $this->stateLifetime($config->cooldownSeconds),
        );
    }

    /**
     * Mirrors FallbackMiddleware's retryable set: a failure that suggests the
     * provider itself is unhealthy (as opposed to a client-side error).
     */
    private function isTrippingFailure(Throwable $e): bool
    {
        // Provider-side faults (connection, rate-limit, 5xx) count towards
        // opening the circuit; our-side faults (auth, client, config) and an
        // already-open circuit do not (ADR-095). Previously only connection and
        // 429 tripped it, so a provider returning 500 repeatedly never opened.
        return FailureClassifier::classify($e)->tripsCircuit();
    }

    /**
     * Circuit key: the provider adapter type for configuration-backed calls,
     * falling back to the (provider-encoding) configuration identifier for
     * ad-hoc calls whose transient configuration carries no model. Two DB
     * configurations on the same provider share one circuit — the provider is
     * what is unhealthy, not the configuration.
     */
    private function circuitKey(ProviderCallContext $context): string
    {
        // The provider the call reaches, whether it comes from a configuration
        // entity or the context's own provider string (a specialized service).
        $provider = $context->telemetryProvider();

        return $provider !== '' ? $provider : $context->telemetryConfigurationIdentifier();
    }

    /**
     * Cache lifetime for a state write. Comfortably outlives the cooldown so an
     * open circuit survives its window, while still decaying a stale failure
     * streak after a period of no traffic.
     */
    private function stateLifetime(int $cooldownSeconds): int
    {
        return max($cooldownSeconds * 2, 60);
    }

    /**
     * Read the circuit configuration from the `circuitBreaker.*` extension
     * settings, mirroring TelemetryMiddleware's tolerant reader: any read
     * failure or missing key falls back to the safe defaults (enabled, with the
     * default threshold and cooldown).
     */
    private function config(): CircuitBreakerConfig
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            $config = [];
        }

        $circuit = \is_array($config['circuitBreaker'] ?? null) ? $config['circuitBreaker'] : [];

        return new CircuitBreakerConfig(
            enabled: !\array_key_exists('enabled', $circuit) || (bool)$circuit['enabled'],
            failureThreshold: $this->positiveIntOr($circuit['failureThreshold'] ?? null, self::DEFAULT_FAILURE_THRESHOLD),
            cooldownSeconds: $this->positiveIntOr($circuit['cooldownSeconds'] ?? null, self::DEFAULT_COOLDOWN_SECONDS),
        );
    }

    private function positiveIntOr(mixed $value, int $default): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return $default;
    }
}
