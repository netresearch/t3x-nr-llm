<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

use Throwable;

/**
 * Thrown when every configuration in a fallback chain (primary + fallbacks)
 * failed with a retryable error. Carries per-attempt errors so callers can
 * reason about the full failure sequence.
 */
final class FallbackChainExhaustedException extends ProviderException
{
    /**
     * @param list<array{configuration: string, error: Throwable}> $attemptErrors
     */
    public function __construct(
        string $message,
        int $code,
        private readonly array $attemptErrors,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param list<array{configuration: string, error: Throwable}> $attempts
     */
    public static function fromAttempts(array $attempts): self
    {
        $configurations = array_map(
            static fn(array $attempt): string => $attempt['configuration'],
            $attempts,
        );
        $last = $attempts === [] ? null : $attempts[array_key_last($attempts)]['error'];

        $message = sprintf(
            'All %d configuration(s) in the fallback chain failed: %s',
            count($attempts),
            implode(' -> ', $configurations),
        );

        return new self($message, 1745712001, $attempts, $last);
    }

    /**
     * @return list<array{configuration: string, error: Throwable}>
     */
    public function getAttemptErrors(): array
    {
        return $this->attemptErrors;
    }

    /**
     * @return list<string>
     */
    public function getAttemptedConfigurations(): array
    {
        return array_map(
            static fn(array $attempt): string => $attempt['configuration'],
            $this->attemptErrors,
        );
    }
}
