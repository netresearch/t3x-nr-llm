<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Derive one outcome per terminal run after its latest write has settled. Evidence is read at execution time; absence of later history is UNKNOWN, not proof of acceptance (ADR-273). @internal.
 */
final readonly class ObservedOutcomeDeriver implements SingletonInterface
{
    /** Minimum settlement delay before an observation is meaningful. */
    public const MIN_WINDOW_DAYS = 1;

    public const DEFAULT_WINDOW_DAYS = 7;

    private const BATCH_SIZE = 200;

    public function __construct(
        private WrittenRecordRepositoryInterface $writes,
        private CallOutcomeRepositoryInterface $outcomes,
    ) {}

    /**
     * Observe eligible terminal runs at $now after at least $windowDays of settlement.
     *
     * @return array<string, int> outcome value => run count
     */
    public function derive(int $windowDays = self::DEFAULT_WINDOW_DAYS, ?int $now = null): array
    {
        $now ??= time();
        $settledAt = $now - (max(self::MIN_WINDOW_DAYS, $windowDays) * 86400);

        $counts = [];
        foreach ($this->writes->findUnansweredCorrelations($settledAt, self::BATCH_SIZE) as $correlationId) {
            $outcome = $this->outcomeForRun($correlationId);
            if (!$outcome instanceof CallOutcome) {
                continue;
            }

            $this->outcomes->record($correlationId, $outcome);
            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * One answer for a run, from every record it wrote.
     *
     * A run can call more than one write tool, and they share a correlation id
     * because that id is the run's uuid (ADR-185). Judging only the first write
     * would let a run whose SECOND write was deleted be recorded as accepted,
     * so the writes are combined by an explicit precedence:
     *
     *     DISCARDED > EDITED > UNKNOWN > ACCEPTED_UNCHANGED
     *
     * A known negative outranks an unknown, because "one of these was thrown
     * away" is a fact whatever else could not be determined. ACCEPTED_UNCHANGED
     * is last on purpose: it may only be claimed when EVERY write of the run is
     * known to have survived untouched.
     *
     * Null when the run has no usable write at all — an outcome about no write
     * would be an outcome about nothing.
     */
    private function outcomeForRun(string $correlationId): ?CallOutcome
    {
        $seen = [];
        foreach ($this->writes->findWritesForCorrelation($correlationId) as $write) {
            $seen[] = $this->classify($write);
        }

        foreach ([CallOutcome::DISCARDED, CallOutcome::EDITED, CallOutcome::UNKNOWN, CallOutcome::ACCEPTED_UNCHANGED] as $candidate) {
            if (in_array($candidate, $seen, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Classify only what retained activity and confirmed record existence establish.
     */
    private function classify(ObservedWrite $write): CallOutcome
    {
        $history = $this->writes->historyAfter($write->record, $write->writtenAt);
        if (!$this->writes->recordExists($write->record) || $this->wasDeleted($history['later'])) {
            return CallOutcome::DISCARDED;
        }

        // Positive later activity is evidence even when earlier history was pruned.
        return $history['later'] === [] ? CallOutcome::UNKNOWN : CallOutcome::EDITED;
    }

    /**
     * Whether any of the later history rows is a deletion.
     *
     * Asked alongside the existence check rather than after it: a soft delete
     * leaves the row in place, and a hard delete leaves no history. Neither
     * alone recognises both, and DISCARDED is one answer, so it is one
     * condition.
     *
     * @param list<int> $actions
     */
    private function wasDeleted(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($this->writes->isDeletion($action)) {
                return true;
            }
        }

        return false;
    }
}
