<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use Netresearch\NrLlm\Service\Telemetry\FallbackHop;
use Netresearch\NrLlm\Service\Telemetry\RoutedCall;
use Netresearch\NrLlm\Service\Telemetry\TelemetryCall;
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
        if ($this->failOnRecord instanceof Throwable) {
            throw $this->failOnRecord;
        }

        $this->records[] = $record;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }

    /** Value returned by successRatePercent(), regardless of $since. */
    public int $successRatePercentReturns = 0;

    /** Value returned by averageLatencyMs(), regardless of $since. */
    public int $averageLatencyMsReturns = 0;

    public function successRatePercent(int $since): int
    {
        return $this->successRatePercentReturns;
    }

    public function averageLatencyMs(int $since): int
    {
        return $this->averageLatencyMsReturns;
    }

    /**
     * Rows recentFallbackHops() hands back, newest first. Deliberately
     * unnarrowed: the SQL implementation filters to served swaps so its
     * $limit counts rescues, but a test states the hops it wants the reader
     * to classify — including the ones the query would have dropped, so the
     * reader's own rule stays under test rather than the stub's.
     *
     * @var list<FallbackHop>
     */
    public array $fallbackHops = [];

    /** The ($since, $limit) pair the last recentFallbackHops() was asked for. */
    public ?int $hopsSince = null;

    public ?int $hopsLimit = null;

    public function recentFallbackHops(int $since, int $limit): array
    {
        $this->hopsSince = $since;
        $this->hopsLimit = $limit;

        return \array_slice($this->fallbackHops, 0, max(1, $limit));
    }

    /**
     * Rows recentRoutedCalls() hands back, newest first. Unnarrowed for the
     * same reason as {@see self::$fallbackHops}: the "carries a decision" rule
     * lives in the SQL, and a test states the rows it wants the reader handed.
     *
     * @var list<RoutedCall>
     */
    public array $routedCalls = [];

    public function recentRoutedCalls(int $since, int $limit): array
    {
        // No ($since, $limit) recording twin to hopsSince/hopsLimit: those exist
        // because FallbackRescueReportTest asserts the window a SERVICE asks
        // for. The only caller here is LlmModuleController, which no unit test
        // drives through this fixture, so the pair would be a declaration
        // nothing reads.
        return \array_slice($this->routedCalls, 0, max(1, $limit));
    }

    /**
     * Rows findByCorrelation() hands back, keyed by correlation id — so a test
     * states which trace holds which calls rather than one global list.
     *
     * @var array<string, list<TelemetryCall>>
     */
    public array $callsByCorrelation = [];

    /** The correlation id the last findByCorrelation() was asked for. */
    public ?string $correlationAsked = null;

    public function findByCorrelation(string $correlationId): array
    {
        $this->correlationAsked = $correlationId;

        return $this->callsByCorrelation[$correlationId] ?? [];
    }
}
