<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepositoryInterface;

/**
 * Records what the purge asked of the outcome table, so the command's
 * retention wiring can be asserted instead of mocked.
 *
 * The cutoff is the point: ADR-176 runs call outcomes on the TELEMETRY window
 * rather than one of their own, and only comparing the two cutoffs proves it.
 */
final class InMemoryCallOutcomeRepository implements CallOutcomeRepositoryInterface
{
    /** The cutoff the last purgeOlderThan() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeOlderThan() reports as deleted. */
    public int $purgeReturns = 0;

    /** @var list<array{correlationId: string, outcome: CallOutcome}> */
    public array $recorded = [];

    public function record(string $correlationId, CallOutcome $outcome): void
    {
        $this->recorded[] = ['correlationId' => $correlationId, 'outcome' => $outcome];
    }

    public function findByCorrelation(string $correlationId): array
    {
        $found = [];
        foreach ($this->recorded as $entry) {
            if ($entry['correlationId'] === $correlationId) {
                $found[] = $entry['outcome'];
            }
        }

        return $found;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }
}
