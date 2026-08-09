<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Resolved circuit breaker tunables (ADR-063).
 *
 * A typed snapshot of the `circuitBreaker.*` extension settings, read once per
 * pipeline run by {@see \Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware}
 * so the rest of the middleware works with named properties rather than
 * repeated string keys.
 */
final readonly class CircuitBreakerConfig
{
    private const DEFAULT_FAILURE_THRESHOLD = 5;

    private const DEFAULT_COOLDOWN_SECONDS = 30;

    public function __construct(
        public bool $enabled,
        public int $failureThreshold,
        public int $cooldownSeconds,
    ) {}

    /**
     * Read the `circuitBreaker.*` extension settings, tolerantly: any read
     * failure or missing key falls back to the safe defaults (enabled, with the
     * default threshold and cooldown).
     *
     * Lives here rather than in the middleware because a reader OUTSIDE the
     * pipeline needs the same answer — a stored {@see CircuitState} only
     * derives a {@see CircuitStatus} against the configured cooldown, so a
     * second reader with its own defaults could report a circuit as half-open
     * that the middleware still fails fast on.
     *
     * @internal
     */
    public static function fromExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            $config = [];
        }

        $circuit = \is_array($config['circuitBreaker'] ?? null) ? $config['circuitBreaker'] : [];

        return new self(
            enabled: !\array_key_exists('enabled', $circuit) || (bool)$circuit['enabled'],
            failureThreshold: self::positiveIntOr($circuit['failureThreshold'] ?? null, self::DEFAULT_FAILURE_THRESHOLD),
            cooldownSeconds: self::positiveIntOr($circuit['cooldownSeconds'] ?? null, self::DEFAULT_COOLDOWN_SECONDS),
        );
    }

    private static function positiveIntOr(mixed $value, int $default): int
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
