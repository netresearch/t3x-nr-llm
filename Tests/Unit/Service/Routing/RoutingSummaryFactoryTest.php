<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Service\Routing\RoutingSummaryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutingSummaryFactory::class)]
final class RoutingSummaryFactoryTest extends TestCase
{
    #[Test]
    public function theCandidateCountCoversRefusedCandidatesToo(): void
    {
        // "How many models were considered" is the question, not "how many
        // survived" — the latter is derivable from the reason set.
        $decision = new RoutingDecision(
            $this->model('a'),
            [
                RoutingCandidate::eligible($this->model('a'), 0.5),
                RoutingCandidate::rejected($this->model('b'), RoutingRejectionReason::CAPABILITY_MISSING),
                RoutingCandidate::rejected($this->model('c'), RoutingRejectionReason::COST_ABOVE_LIMIT),
            ],
            RoutingPolicyMode::BALANCED,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertSame('balanced', $summary->policyMode);
        self::assertSame(3, $summary->candidateCount);
    }

    #[Test]
    public function eachRejectionReasonIsRecordedOnceAndSorted(): void
    {
        // The stored value is a SET, so two rows describing the same outcome
        // compare equal regardless of how many models each reason refused or
        // what order they came back in.
        $decision = new RoutingDecision(
            null,
            [
                RoutingCandidate::rejected($this->model('a'), RoutingRejectionReason::COST_ABOVE_LIMIT),
                RoutingCandidate::rejected($this->model('b'), RoutingRejectionReason::CAPABILITY_MISSING),
                RoutingCandidate::rejected($this->model('c'), RoutingRejectionReason::CAPABILITY_MISSING),
            ],
            RoutingPolicyMode::PROVIDER_PRIORITY,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertSame(['CAPABILITY_MISSING', 'COST_ABOVE_LIMIT'], $summary->rejectionReasons);
    }

    #[Test]
    public function aSignalWithNoDataAnywhereCountsAsNotUsed(): void
    {
        // The distinction the column exists for: the mode weighed quality, but
        // nobody had measured any candidate, so the ranking fell through to
        // provider priority exactly as the default mode would have. Recording
        // "quality was used" here would explain an outcome quality never
        // touched.
        $decision = new RoutingDecision(
            $this->model('a'),
            [
                RoutingCandidate::eligible($this->model('a'), 0.5, ['quality' => null, 'health' => null]),
                RoutingCandidate::eligible($this->model('b'), 0.5, ['quality' => null, 'health' => null]),
            ],
            RoutingPolicyMode::QUALITY,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertFalse($summary->qualitySignalUsed);
        self::assertFalse($summary->healthSignalUsed);
        self::assertFalse($summary->costSignalUsed);
    }

    #[Test]
    public function oneMeasuredCandidateIsEnoughToCountTheSignalAsUsed(): void
    {
        $decision = new RoutingDecision(
            $this->model('a'),
            [
                RoutingCandidate::eligible($this->model('a'), 0.9, ['quality' => null, 'health' => null, 'cost' => null]),
                RoutingCandidate::eligible($this->model('b'), 0.2, ['quality' => 0.2, 'health' => null, 'cost' => null]),
            ],
            RoutingPolicyMode::BALANCED,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertTrue($summary->qualitySignalUsed);
        self::assertFalse($summary->healthSignalUsed);
        self::assertFalse($summary->costSignalUsed);
    }

    #[Test]
    public function aCollectedSignalTheModeWeighsAtZeroIsNotRecordedAsUsed(): void
    {
        // mode=quality + preferLowestCost, both operator-settable: the ranker
        // COLLECTS cost (signalsFor() asks for it whenever the criteria prefer
        // lowest cost), but RoutingPolicyMode::QUALITY->costWeight() is 0.0, so
        // score() skips it and no candidate's score contains a cost term.
        // Recording "cost was used" here would explain the outcome with a signal
        // that moved nothing.
        $decision = new RoutingDecision(
            $this->model('a'),
            [
                RoutingCandidate::eligible($this->model('a'), 0.9, ['quality' => 0.9, 'health' => null, 'cost' => 0.8]),
                RoutingCandidate::eligible($this->model('b'), 0.2, ['quality' => 0.2, 'health' => null, 'cost' => 0.1]),
            ],
            RoutingPolicyMode::QUALITY,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertSame(0.0, RoutingPolicyMode::QUALITY->costWeight(), 'the premise of this test');
        self::assertFalse($summary->costSignalUsed);
        self::assertTrue($summary->qualitySignalUsed, 'quality does carry weight in this mode');
    }

    #[Test]
    public function aWeighedSignalWithDataIsStillRecordedAsUsed(): void
    {
        // The mirror of the case above, so the weight guard cannot be satisfied
        // by simply never reporting cost: economy weighs cost at 0.6.
        $decision = new RoutingDecision(
            $this->model('a'),
            [RoutingCandidate::eligible($this->model('a'), 0.7, ['quality' => null, 'health' => null, 'cost' => 0.8])],
            RoutingPolicyMode::ECONOMY,
        );

        self::assertTrue((new RoutingSummaryFactory())->fromDecision($decision)->costSignalUsed);
    }

    #[Test]
    public function aMeasuredZeroIsData(): void
    {
        // 0.0 is a measurement; null is its absence. Collapsing them would
        // report "nobody measured this provider" for a provider measured as
        // completely unhealthy.
        $decision = new RoutingDecision(
            $this->model('a'),
            [RoutingCandidate::eligible($this->model('a'), 0.0, ['quality' => null, 'health' => 0.0])],
            RoutingPolicyMode::BALANCED,
        );

        self::assertTrue((new RoutingSummaryFactory())->fromDecision($decision)->healthSignalUsed);
    }

    #[Test]
    public function refusedCandidatesCarryNoSignalsAndCannotMarkOneUsed(): void
    {
        // A rejected candidate has no score and no signals (ADR-142). Reading
        // signals off the whole candidate list rather than the eligible half
        // would still report "used" here only if something invented values for
        // refused models — this pins that it does not.
        $decision = new RoutingDecision(
            null,
            [RoutingCandidate::rejected($this->model('a'), RoutingRejectionReason::CONTEXT_TOO_SMALL)],
            RoutingPolicyMode::QUALITY,
        );

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertFalse($summary->qualitySignalUsed);
        self::assertSame(1, $summary->candidateCount);
    }

    #[Test]
    public function anEmptyCatalogueSummarisesAsZeroCandidatesAndNoReasons(): void
    {
        $decision = new RoutingDecision(null, [], RoutingPolicyMode::ECONOMY);

        $summary = (new RoutingSummaryFactory())->fromDecision($decision);

        self::assertSame(0, $summary->candidateCount);
        self::assertSame([], $summary->rejectionReasons);
        self::assertSame('economy', $summary->policyMode);
    }

    private function model(string $name): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('openai');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setIdentifier('model-' . $name);
        $model->setModelId('model-' . $name);
        $model->setName($name);
        $model->setProvider($provider);

        return $model;
    }
}
