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
 * What became of the records this extension wrote (ADR-185).
 *
 * The observed half of ADR-176's outcome signal, and the half that was blocked
 * until ADR-182 persisted a write target. It reads two things — the recorded
 * writes whose observation window has closed, and what `sys_history` says
 * happened to those records afterwards — and writes one outcome row per write.
 *
 * It reads NO approval state, and holds no collaborator that could. ADR-176
 * separated approval from quality because an approval can be withheld for
 * governance reasons that say nothing about the model, and ADR-184 then added a
 * refusal that is explicitly not a signal either. A stale-refused approval
 * produces no outcome here, because it produced no write.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ObservedOutcomeDeriver implements SingletonInterface
{
    /**
     * The shortest window that still means anything.
     *
     * A window of zero would classify a record the same second it was written,
     * which reports every write as untouched. Clamped rather than refused, the
     * way the privacy retention window clamps (ADR-064).
     */
    public const MIN_WINDOW_DAYS = 1;

    public const DEFAULT_WINDOW_DAYS = 7;

    private const BATCH_SIZE = 200;

    public function __construct(
        private WrittenRecordRepositoryInterface $writes,
        private CallOutcomeRepositoryInterface $outcomes,
    ) {}

    /**
     * Derive and record an outcome for every run whose writes have settled.
     *
     * Returns what it decided, by case, so a caller can print it and an
     * operator can see the shape of the answer rather than a total. A pass that
     * finds nothing returns an empty map, which is not a failure — most will,
     * because most writes are still inside their window.
     *
     * @param int $windowDays how long a record is watched before it is judged
     * @param int $now        the clock, injected so a test does not need one
     *
     * @return array<string, int> outcome value => how many
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
     * What happened to one written record.
     *
     * The order of the questions is the decision. Deletion first, because a
     * record that is gone is gone whatever its history says. Then the missing
     * own row, because history that cannot be read cannot be reasoned from —
     * and answering ACCEPTED_UNCHANGED there would be inferring acceptance from
     * an absence, which is the one thing ADR-185 forbids by name.
     */
    private function classify(ObservedWrite $write): CallOutcome
    {
        $history = $this->writes->historyAfter($write->record, $write->writtenAt);

        if (!$this->writes->recordExists($write->record) || $this->wasDeleted($history['later'])) {
            return CallOutcome::DISCARDED;
        }

        // History is trimmed oldest-first, so a record whose oldest RETAINED row
        // is newer than our write has had our write — and anything between —
        // removed. Asking whether SOME row exists at or before the write proves
        // nothing: an older row that survived answers yes for a write whose own
        // row is long gone, and the result would be ACCEPTED_UNCHANGED inferred
        // from an absence of evidence.
        $oldest = $history['oldestRetained'];
        if ($oldest === null || $oldest > $write->writtenAt) {
            return CallOutcome::UNKNOWN;
        }

        // Any later row is a human touching the record. NOT "a human changed
        // the text we generated" — the write target names a record and not the
        // fields it wrote (ADR-182), so this over-reports for an editor who
        // fixed something unrelated on the same record. ADR-185 states that
        // limitation and names the measurement that would justify fixing it.
        return $history['later'] === [] ? CallOutcome::ACCEPTED_UNCHANGED : CallOutcome::EDITED;
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
