<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

/**
 * Immutable per-provider circuit state (ADR-063).
 *
 * Stores only what is needed to reconstruct the circuit's behaviour: the number
 * of consecutive tripping failures and, when the circuit is open, the UNIX
 * timestamp it opened at. The {@see CircuitStatus} (closed / open / half-open)
 * is derived from these two fields plus the current clock and the configured
 * cooldown — see {@see CircuitBreakerMiddleware}. Deriving rather than storing
 * the status means a stored state cannot disagree with the clock (an "open"
 * flag that outlived its cooldown).
 *
 * Persisted as a plain array via {@see self::toArray()} so it round-trips
 * through the TYPO3 cache frontend without object-serialisation coupling.
 */
final readonly class CircuitState
{
    public function __construct(
        public int $consecutiveFailures = 0,
        public ?int $openedAt = null,
    ) {}

    /**
     * A fully closed circuit: no failures recorded, not open.
     */
    public static function closed(): self
    {
        return new self(0, null);
    }

    /**
     * Derive the circuit status against the current clock and the configured
     * cooldown. Open while still within the cooldown window; half-open once it
     * has elapsed (a single probe is due); closed when never opened.
     */
    public function status(int $now, int $cooldownSeconds): CircuitStatus
    {
        if ($this->openedAt === null) {
            return CircuitStatus::Closed;
        }

        return ($now - $this->openedAt) < $cooldownSeconds
            ? CircuitStatus::Open
            : CircuitStatus::HalfOpen;
    }

    /**
     * Seconds until an open circuit becomes half-open (0 once it already has, or
     * when the circuit is not open). Used for the retry-after hint on
     * {@see \Netresearch\NrLlm\Provider\Exception\CircuitOpenException}.
     */
    public function secondsUntilHalfOpen(int $now, int $cooldownSeconds): int
    {
        if ($this->openedAt === null) {
            return 0;
        }

        return max(0, $cooldownSeconds - ($now - $this->openedAt));
    }

    /**
     * Reconstruct from the cached array shape. Unknown / malformed input decays
     * to a closed circuit (fail-safe: a corrupt entry must not wedge a provider
     * permanently open).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $failures = $data['consecutiveFailures'] ?? 0;
        $openedAt = $data['openedAt'] ?? null;

        return new self(
            \is_int($failures) && $failures >= 0 ? $failures : 0,
            \is_int($openedAt) && $openedAt > 0 ? $openedAt : null,
        );
    }

    /**
     * @return array{consecutiveFailures: int, openedAt: int|null}
     */
    public function toArray(): array
    {
        return [
            'consecutiveFailures' => $this->consecutiveFailures,
            'openedAt'            => $this->openedAt,
        ];
    }

    /**
     * True when this state carries nothing worth persisting — a closed circuit
     * with no failure streak. Lets the hot success path skip a redundant cache
     * write.
     */
    public function isPristine(): bool
    {
        return $this->consecutiveFailures === 0 && $this->openedAt === null;
    }
}
