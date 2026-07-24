<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;

/**
 * In-memory governance-event repository for command and widget unit tests:
 * captures recorded events and the purge cutoff, and returns pre-set aggregate
 * maps, so the flows can be exercised without a database.
 */
final class InMemoryGovernanceEventRepository implements GovernanceEventRepositoryInterface
{
    /** @var list<GovernanceEvent> */
    public array $recorded = [];

    /** The cutoff timestamp the last purgeOlderThan() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeOlderThan() reports as deleted. */
    public int $purgeReturns = 0;

    /** @var array<string, int> value => count returned by countByDecision() */
    public array $countByDecisionReturns = [];

    /** @var array<string, int> value => count returned by countToolDenialsByReason() */
    public array $countToolDenialsByReasonReturns = [];

    /** @var array<string, int> tool_name => count returned by countToolDecisionsByName() */
    public array $countToolDecisionsByNameReturns = [];

    public function record(GovernanceEvent $event): void
    {
        $this->recorded[] = $event;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }

    public function countByDecision(int $since = 0): array
    {
        return $this->countByDecisionReturns;
    }

    public function countToolDenialsByReason(int $since = 0): array
    {
        return $this->countToolDenialsByReasonReturns;
    }

    public function countToolDecisionsByName(int $since = 0): array
    {
        return $this->countToolDecisionsByNameReturns;
    }
}
