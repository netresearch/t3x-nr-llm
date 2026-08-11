<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingSummary;

/**
 * Reduces a {@see RoutingDecision} to the six scalars telemetry persists
 * (ADR-156).
 *
 * A separate class rather than a static method on {@see RoutingSummary},
 * because the summary crosses the `@api` boundary and the decision it is
 * derived from is `@internal` — a public factory on the DTO would put an
 * internal type into a published signature (the ADR-127 closure rule).
 *
 * @internal
 */
final readonly class RoutingSummaryFactory
{
    /**
     * The signal names {@see CandidateRanker} collects, in the order the
     * summary reports them.
     */
    private const QUALITY = 'quality';

    private const HEALTH = 'health';

    private const COST = 'cost';

    public function fromDecision(RoutingDecision $decision): RoutingSummary
    {
        $eligible = $decision->eligibleCandidates();
        $mode     = $decision->mode;

        return new RoutingSummary(
            policyMode: $mode->value,
            candidateCount: count($decision->candidates),
            rejectionReasons: $this->distinctReasons($decision),
            qualitySignalUsed: $this->signalUsed($eligible, self::QUALITY, $mode->qualityWeight()),
            healthSignalUsed: $this->signalUsed($eligible, self::HEALTH, $mode->healthWeight()),
            costSignalUsed: $this->signalUsed($eligible, self::COST, $mode->costWeight()),
        );
    }

    /**
     * Each rejection reason once, sorted, so the stored value is a set rather
     * than a per-model list.
     *
     * Sorted because the column is compared across rows: the same two reasons
     * in a different candidate order must read as the same outcome, and
     * `ORDER BY`-free repository output does not guarantee one.
     *
     * @return list<string>
     */
    private function distinctReasons(RoutingDecision $decision): array
    {
        $names = [];
        foreach ($decision->rejectedCandidates() as $candidate) {
            $reason = $candidate->rejectionReason;
            if ($reason === null) {
                continue;
            }

            $names[$reason->name] = true;
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Whether a signal actually moved this decision: it carried weight in this
     * mode AND at least one eligible candidate had a measured value.
     *
     * Both halves are load-bearing, and each on its own would over-report.
     *
     * A weighted signal with no data contributes neither value nor weight — see
     * {@see CandidateRanker::score()} — so a decision made in `quality` mode
     * against a catalogue nobody has scored ranks exactly as the default mode
     * would. "The mode was quality" and "quality decided anything" are
     * different facts, and only the second one explains the outcome.
     *
     * A collected signal with zero weight is the mirror image, and the reason
     * this asks the mode rather than only reading the candidates:
     * {@see CandidateRanker::signalsFor()} collects `cost` whenever the criteria
     * set `preferLowestCost`, but {@see \Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode::QUALITY}
     * weighs cost at 0.0, so `score()` skips it. `quality` + `preferLowestCost` — both
     * operator-settable — would otherwise record a cost signal that contributed
     * nothing to any score. The weight here is the same one `score()` derives:
     * where the ranker does not collect cost, the value is null and the second
     * half rejects it anyway.
     *
     * The cost TIEBREAK is a different mechanism and deliberately not reported
     * here. `preferLowestCost` also orders equal-scoring candidates by raw cost
     * in {@see CandidateRanker::compare()}; that is the criteria set acting, not
     * the ranking signal, and the criteria are the caller's own input rather
     * than something the decision has to explain back.
     *
     * @param list<RoutingCandidate> $eligible
     * @param float                  $weight   the signal's weight in this mode, as
     *                                         {@see CandidateRanker::score()} derives it
     */
    private function signalUsed(array $eligible, string $signal, float $weight): bool
    {
        if ($weight <= 0.0) {
            return false;
        }

        foreach ($eligible as $candidate) {
            if (($candidate->signals[$signal] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
