<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Health;

/**
 * Read boundary over the telemetry log for provider health (ADR-063).
 *
 * Separate from {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface},
 * which ADR-058 deliberately keeps to append/purge only ("no read path —
 * telemetry is append-only and analytics query the table directly"). Health
 * scoring is exactly such an analytics reader, so it lives behind its own
 * narrow interface rather than widening the telemetry contract.
 */
interface ProviderHealthRepositoryInterface
{
    /**
     * Aggregate per-provider health over the rows created at or after the given
     * UNIX timestamp. Only rows carrying a non-empty provider are considered
     * (ad-hoc direct calls record no provider). Providers with no rows in the
     * window are simply absent from the result.
     *
     * @return array<string, ProviderHealthScore> keyed by provider identifier
     */
    public function scoresSince(int $sinceTimestamp): array;
}
