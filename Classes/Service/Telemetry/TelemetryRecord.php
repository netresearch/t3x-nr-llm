<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * One immutable telemetry row, produced by TelemetryMiddleware and written by
 * TelemetryRepository (ADR-058).
 *
 * Privacy by construction: this DTO has no field for a prompt, a response, or
 * an exception message. `errorClass` is the exception FQCN only — messages can
 * carry payload fragments, so they are never captured here.
 */
final readonly class TelemetryRecord
{
    public function __construct(
        public string $correlationId,
        public string $operation,
        public string $provider,
        public string $model,
        public string $configurationIdentifier,
        public int $beUser,
        public bool $success,
        public string $errorClass,
        public int $latencyMs,
        public bool $cacheHit,
        public int $fallbackAttempts,
    ) {}
}
