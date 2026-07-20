<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\FailureClass;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Maps a thrown failure onto a {@see FailureClass} (ADR-095).
 *
 * The one place that decides what kind of failure a throwable represents, so
 * the retry/fallback middleware, the circuit breaker and the streaming
 * dispatcher no longer each keep a private `instanceof` ladder that can drift.
 * Pure and stateless — a static call, deterministic, unit-tested directly.
 *
 * Scope note: this recognises the provider exception family (ADR-080) and the
 * PSR-18 network contract. The specialized-service exceptions travel a path
 * that does not (yet) reach these middleware, so classifying them is deferred
 * to the lifecycle migration that routes those calls through the pipeline.
 */
final readonly class FailureClassifier
{
    public static function classify(Throwable $e): FailureClass
    {
        return match (true) {
            $e instanceof CircuitOpenException => FailureClass::CIRCUIT_OPEN,
            $e instanceof ProviderConnectionException,
            $e instanceof NetworkExceptionInterface => FailureClass::CONNECTION,
            $e instanceof ProviderConfigurationException => FailureClass::CONFIGURATION,
            $e instanceof ProviderResponseException => self::fromStatus($e->getCode()),
            default => FailureClass::UNKNOWN,
        };
    }

    private static function fromStatus(int $status): FailureClass
    {
        return match (true) {
            $status === 401, $status === 403 => FailureClass::AUTH,
            $status === 429 => FailureClass::RATE_LIMIT,
            $status >= 500 && $status <= 599 => FailureClass::SERVER_ERROR,
            $status >= 400 && $status <= 499 => FailureClass::CLIENT_ERROR,
            default => FailureClass::UNKNOWN,
        };
    }
}
