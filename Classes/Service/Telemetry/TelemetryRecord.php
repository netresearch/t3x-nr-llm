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
    /**
     * @param string $configurationIdentifier       the configuration the caller REQUESTED
     * @param string $servedConfigurationIdentifier the configuration that ANSWERED. Equal to the
     *                                              requested one unless a fallback swap served the
     *                                              run; a run nothing served keeps the requested
     *                                              values. The three served* fields always move
     *                                              together — a row never mixes one configuration's
     *                                              identifier with another's provider.
     * @param ?int   $timeToFirstTokenMs            wall-clock milliseconds from the start of
     *                                              the run to the first streamed chunk. Only
     *                                              the streaming lifecycle (ADR-062) supplies
     *                                              it; every non-streaming pipeline run leaves
     *                                              it null (there is no partial-response
     *                                              milestone to measure), which is stored as
     *                                              SQL NULL — distinct from a genuine 0 ms.
     */
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
        public string $servedConfigurationIdentifier,
        public string $servedProvider,
        public string $servedModel,
        public ?int $timeToFirstTokenMs = null,
    ) {}
}
