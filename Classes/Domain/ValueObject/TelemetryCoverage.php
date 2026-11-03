<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * How far back per-call telemetry reaches (ADR-248).
 *
 * `$enabled` is the `telemetry.enabled` extension setting: while it is off,
 * no new calls are counted. `$retentionDays` is the window after which the
 * purge command deletes rows. `$oldestCallAt` is the oldest row still stored
 * (null when there is none), which is the measured bound: counts for a period
 * starting before it are incomplete.
 *
 * @api
 */
final readonly class TelemetryCoverage
{
    public function __construct(
        public bool $enabled,
        public int $retentionDays,
        public ?int $oldestCallAt,
    ) {}
}
