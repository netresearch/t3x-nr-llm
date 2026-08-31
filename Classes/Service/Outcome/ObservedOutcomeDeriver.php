<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\Enum\CallOutcomeSource;
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
     * Derive and record outcomes for every write whose window has closed.
     *
     * Returns what it decided, by case, so a caller can print it and an
     * operator can see the shape of the answer rather than a total. A run that
     * finds nothing returns an empty map, which is not a failure — most runs
     * will, because most writes are still inside their window.
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
        foreach ($this->writes->findWritesSettledBefore($settledAt, self::BATCH_SIZE) as $write) {
            // Already answered. The window has closed, so the answer is final
            // and re-deriving it would spend two reads to reach the same value.
            if ($this->alreadyObserved($write->correlationId)) {
                continue;
            }

            $outcome = $this->classify($write);
            $this->outcomes->record($write->correlationId, $outcome);

            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        return $counts;
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
        if (!$this->writes->recordExists($write->record)) {
            return CallOutcome::DISCARDED;
        }

        $history = $this->writes->historyAfter($write->record, $write->writtenAt);

        foreach ($history['later'] as $action) {
            if ($this->writes->isDeletion($action)) {
                return CallOutcome::DISCARDED;
            }
        }

        if (!$history['hasOwnRow']) {
            return CallOutcome::UNKNOWN;
        }

        // Any later row is a human touching the record. NOT "a human changed
        // the text we generated" — the write target names a record and not the
        // fields it wrote (ADR-182), so this over-reports for an editor who
        // fixed something unrelated on the same record. ADR-185 states that
        // limitation and names the measurement that would justify fixing it.
        return $history['later'] === [] ? CallOutcome::ACCEPTED_UNCHANGED : CallOutcome::EDITED;
    }

    private function alreadyObserved(string $correlationId): bool
    {
        foreach ($this->outcomes->findByCorrelation($correlationId) as $outcome) {
            if ($outcome->source() === CallOutcomeSource::OBSERVED) {
                return true;
            }
        }

        return false;
    }
}
