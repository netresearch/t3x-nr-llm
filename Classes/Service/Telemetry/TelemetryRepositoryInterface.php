<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * Persistence boundary for provider pipeline telemetry rows (ADR-058).
 *
 * Deliberately narrow: append one row, or purge old rows. There is no update
 * or read path — telemetry is append-only and analytics query the table
 * directly.
 */
interface TelemetryRepositoryInterface
{
    /**
     * Append one telemetry row. Never throws for a caller-visible reason:
     * telemetry must not break the call it observes (see TelemetryMiddleware).
     */
    public function record(TelemetryRecord $record): void;

    /**
     * Delete rows created strictly before the given UNIX timestamp.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $timestamp): int;
}
