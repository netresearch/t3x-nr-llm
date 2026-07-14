<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Throwable;

/**
 * In-memory telemetry repository for unit tests.
 *
 * Captures the DTOs a collaborator builds (so assertions verify the produced
 * record, never a mock return) and the cutoff a purge was asked to run.
 */
final class InMemoryTelemetryRepository implements TelemetryRepositoryInterface
{
    /** @var list<TelemetryRecord> */
    public array $records = [];

    /** When set, record() throws it — to exercise the fail-soft path. */
    public ?Throwable $failOnRecord = null;

    /** The cutoff timestamp the last purgeOlderThan() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeOlderThan() reports as deleted. */
    public int $purgeReturns = 0;

    public function record(TelemetryRecord $record): void
    {
        if ($this->failOnRecord !== null) {
            throw $this->failOnRecord;
        }

        $this->records[] = $record;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }
}
