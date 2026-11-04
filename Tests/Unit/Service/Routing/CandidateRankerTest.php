<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Evaluation\ModelQualityScoreProviderInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
use Netresearch\NrLlm\Service\Routing\ModelCallHistoryInterface;
use Netresearch\NrLlm\Service\Routing\ModelCallStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CandidateRanker::class)]
final class CandidateRankerTest extends TestCase
{
    #[Test]
    public function theDefaultModeReproducesTheOrderingThisExtensionAlwaysApplied(): void
    {
        // The compatibility case, and the reason PROVIDER_PRIORITY is the
        // default: provider priority decides, then the default-model flag, then
        // the sorting field. No measured signal participates.
        $low     = $this->model('low', providerPriority: 10);
        $high    = $this->model('high', providerPriority: 90);
        $default = $this->model('default', providerPriority: 10, isDefault: true);

        $ranked = (new CandidateRanker())->rank([$low, $default, $high], RoutingPolicyMode::PROVIDER_PRIORITY, []);

        self::assertSame(['high', 'default', 'low'], $this->ids($ranked));
    }

    #[Test]
    public function withoutSignalDataEveryCandidateScoresTheSame(): void
    {
        // What makes the compatibility above hold by construction rather than
        // by luck: with nothing measured there is no score to break the tie, so
        // the established tiebreaks decide.
        $ranked = (new CandidateRanker())->rank(
            [$this->model('a'), $this->model('b')],
            RoutingPolicyMode::BALANCED,
            [],
        );

        self::assertSame([0.5, 0.5], array_map(static fn (RoutingCandidate $c): ?float => $c->score, $ranked));
    }

    #[Test]
    public function measuredQualityOrdersCandidatesOfEqualProviderPriority(): void
    {
        $ranker = new CandidateRanker($this->quality(['model-poor' => 0.2, 'model-good' => 0.9]));

        $ranked = $ranker->rank(
            [$this->model('poor'), $this->model('good')],
            RoutingPolicyMode::QUALITY,
            [],
        );

        self::assertSame(['good', 'poor'], $this->ids($ranked));
    }

    #[Test]
    public function providerPriorityOutranksEveryMeasuredSignal(): void
    {
        // An operator's explicit ordering is an instruction; a score is
        // evidence. Evidence does not overrule an instruction.
        $ranker = new CandidateRanker($this->quality(['model-poor' => 0.1, 'model-good' => 1.0]));

        $ranked = $ranker->rank(
            [$this->model('good', providerPriority: 10), $this->model('poor', providerPriority: 90)],
            RoutingPolicyMode::QUALITY,
            [],
        );

        self::assertSame(['poor', 'good'], $this->ids($ranked));
    }

    #[Test]
    public function anUnmeasuredModelIsNotPenalisedForTheAbsence(): void
    {
        // "No quality data" must not read as "bad quality". The unmeasured
        // model sits at the neutral midpoint, so it beats a model measured
        // WORSE than neutral and loses to one measured better.
        $ranker = new CandidateRanker($this->quality(['model-bad' => 0.1]));

        $ranked = $ranker->rank(
            [$this->model('bad'), $this->model('unknown')],
            RoutingPolicyMode::QUALITY,
            [],
        );

        self::assertSame(['unknown', 'bad'], $this->ids($ranked));
        self::assertNull($ranked[0]->signals['quality'], 'the absence is recorded as absent, not as a value');
    }

    #[Test]
    public function anUnsampledProviderContributesNoHealthSignal(): void
    {
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('scoreFor')->willReturn(ProviderHealthScore::unknown('openai'));

        $ranked = (new CandidateRanker(null, $health))->rank([$this->model('a')], RoutingPolicyMode::BALANCED, []);

        self::assertNull(
            $ranked[0]->signals['health'],
            'an unsampled provider is an absence, not a mid-range measurement',
        );
    }

    #[Test]
    public function aMeasuredHealthScoreOrdersCandidates(): void
    {
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('scoreFor')->willReturnCallback(
            static fn (string $provider): ProviderHealthScore => $provider === 'flaky'
                ? ProviderHealthScore::fromSamples('flaky', 10, 3, 100.0)
                : ProviderHealthScore::fromSamples('solid', 10, 10, 100.0),
        );

        $ranked = (new CandidateRanker(null, $health))->rank(
            [$this->model('flaky', adapterType: 'flaky'), $this->model('solid', adapterType: 'solid')],
            RoutingPolicyMode::BALANCED,
            [],
        );

        self::assertSame(['solid', 'flaky'], $this->ids($ranked));
    }

    #[Test]
    public function economyWeighsCostWithoutBeingAskedTo(): void
    {
        $ranked = (new CandidateRanker())->rank(
            [$this->model('expensive', costInput: 80), $this->model('cheap', costInput: 5)],
            RoutingPolicyMode::ECONOMY,
            [],
        );

        self::assertSame(['cheap', 'expensive'], $this->ids($ranked));
    }

    #[Test]
    public function balancedIgnoresCostUntilTheCriteriaAskForIt(): void
    {
        $ranker = new CandidateRanker();
        $models = [$this->model('expensive', costInput: 80), $this->model('cheap', costInput: 5)];

        $withoutPreference = $ranker->rank($models, RoutingPolicyMode::BALANCED, []);
        self::assertNull($withoutPreference[0]->signals['cost'] ?? null, 'cost was not collected');

        $withPreference = $ranker->rank($models, RoutingPolicyMode::BALANCED, ['preferLowestCost' => true]);
        self::assertSame(['cheap', 'expensive'], $this->ids($withPreference));
    }

    #[Test]
    public function anUnpricedModelDoesNotWinACostComparisonByNotBeingMeasured(): void
    {
        $ranked = (new CandidateRanker())->rank(
            [$this->model('unpriced', costInput: 0, costOutput: 0), $this->model('cheap', costInput: 5, costOutput: 5)],
            RoutingPolicyMode::ECONOMY,
            [],
        );

        self::assertSame(['cheap', 'unpriced'], $this->ids($ranked));
    }

    #[Test]
    public function aPriceBelowOneCentIsCheaperNotUnpriced(): void
    {
        // Decimal prices (ADR-211): 0.042 USD per million tokens is 4.2 cents,
        // and a fraction of a cent must neither round to "unpriced" nor tie.
        $ranked = (new CandidateRanker())->rank(
            [
                $this->model('unpriced', costInput: 0.0, costOutput: 0.0),
                $this->model('cent', costInput: 1.0, costOutput: 0.0),
                $this->model('fraction', costInput: 0.4, costOutput: 0.0),
                $this->model('jev', costInput: 4.2, costOutput: 0.0),
            ],
            RoutingPolicyMode::ECONOMY,
            [],
        );

        self::assertSame(['fraction', 'cent', 'jev', 'unpriced'], $this->ids($ranked));
    }

    #[Test]
    public function noSignalsAreCollectedInTheDefaultMode(): void
    {
        // Asking a quality store and a telemetry window per candidate costs
        // queries whose answers this mode would not read.
        $quality = $this->createMock(ModelQualityScoreProviderInterface::class);
        $quality->expects(self::never())->method('getQualityScore');

        (new CandidateRanker($quality))->rank([$this->model('a')], RoutingPolicyMode::PROVIDER_PRIORITY, []);
    }

    #[Test]
    public function healthIsLookedUpByTheAdapterTypeTelemetryRecords(): void
    {
        // Telemetry names a provider by its adapter type, not by the record's
        // identifier; asking by identifier found nothing for a provider whose
        // two names differ, so its health never reached the ranking.
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('scoreFor')->willReturnCallback(
            static fn (string $provider): ProviderHealthScore => $provider === 'openai'
                ? ProviderHealthScore::fromSamples('openai', 10, 10, 100.0)
                : ProviderHealthScore::unknown($provider),
        );

        $ranked = (new CandidateRanker(null, $health))->rank(
            [$this->model('a', providerIdentifier: 'openai-production')],
            RoutingPolicyMode::BALANCED,
            [],
        );

        self::assertNotNull($ranked[0]->signals['health']);
    }

    #[Test]
    public function callHistoryRanksTheModelThatAnsweredFasterFirst(): void
    {
        // The demo's measured chat figures: the default model is the slower one,
        // so without the history the default-model tiebreak would pick it.
        $ranker = new CandidateRanker(null, null, $this->history([
            'model-sol'  => new ModelCallStats(175, 4259.0, 175, 0.0067),
            'model-sol1' => new ModelCallStats(9, 9094.0, 7, 0.0184),
        ]));
        $models = [$this->model('sol1', isDefault: true), $this->model('sol')];

        $without = $ranker->rank($models, RoutingPolicyMode::BALANCED, []);
        self::assertSame(['sol1', 'sol'], $this->ids($without), 'no operation: no history, the tiebreak decides');
        self::assertArrayNotHasKey('latency', $without[0]->signals);

        $ranked = $ranker->rank($models, RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat);

        $mean = sqrt(4259 * 9094);
        self::assertSame(['sol', 'sol1'], $this->ids($ranked));
        self::assertEqualsWithDelta($mean / ($mean + 4259), $ranked[0]->signals['latency'], 0.0001);
        self::assertEqualsWithDelta($mean / ($mean + 9094), $ranked[1]->signals['latency'], 0.0001);
    }

    #[Test]
    public function aSoleMeasuredCandidateIsNotPromotedForBeingMeasured(): void
    {
        // The newcomer has no history yet; the incumbent's history must not
        // lift it above the newcomer just because it exists.
        $ranker = new CandidateRanker(null, null, $this->history([
            'model-incumbent' => new ModelCallStats(50, 20000.0, 50, 0.5),
        ]));
        $models = [$this->model('incumbent'), $this->model('newcomer', isDefault: true)];

        $ranked = $ranker->rank($models, RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat);

        self::assertSame(['newcomer', 'incumbent'], $this->ids($ranked), 'the default-model tiebreak still decides');
        self::assertEqualsWithDelta(0.5, $ranked[1]->signals['latency'], 0.0001);
    }

    #[Test]
    public function aFasterModelDoesNotOutrankOneWhoseProviderNeverFails(): void
    {
        // The demo's latencies, the faster model's provider failing one first
        // attempt in five: reliability must still win in the balanced mode.
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('scoreFor')->willReturnCallback(
            static fn (string $provider): ProviderHealthScore => $provider === 'flaky'
                ? ProviderHealthScore::fromSamples('flaky', 10, 8, 4259.0)
                : ProviderHealthScore::fromSamples('solid', 10, 10, 9094.0),
        );
        $history = $this->historyByKey([
            ModelCallStats::key('flaky', 'model-fast', 'chat')   => new ModelCallStats(50, 4259.0, 0, null),
            ModelCallStats::key('solid', 'model-steady', 'chat') => new ModelCallStats(50, 9094.0, 0, null),
        ]);

        $ranked = (new CandidateRanker(null, $health, $history))->rank(
            [$this->model('fast', adapterType: 'flaky'), $this->model('steady', adapterType: 'solid')],
            RoutingPolicyMode::BALANCED,
            [],
            ProviderOperation::Chat,
        );

        self::assertSame(['steady', 'fast'], $this->ids($ranked));
    }

    #[Test]
    public function callHistoryIsLookedUpByTheServingAdapterModelAndOperation(): void
    {
        // Telemetry names the adapter type, not the provider record's
        // identifier, and keeps each operation apart.
        $ranker = new CandidateRanker(null, null, $this->historyByKey([
            ModelCallStats::key('openai', 'model-a', 'tools')           => new ModelCallStats(20, 1000.0, 0, null),
            ModelCallStats::key('openai-production', 'model-a', 'chat') => new ModelCallStats(20, 1000.0, 0, null),
        ]));
        $models = [$this->model('a', providerIdentifier: 'openai-production')];

        self::assertNotNull($ranker->rank($models, RoutingPolicyMode::BALANCED, [], ProviderOperation::Tools)[0]->signals['latency']);
        self::assertNull($ranker->rank($models, RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat)[0]->signals['latency']);
    }

    #[Test]
    public function callHistoryIsNotReadWhenSwitchedOffOrInTheDefaultMode(): void
    {
        $off = $this->createMock(ModelCallHistoryInterface::class);
        $off->method('enabled')->willReturn(false);
        $off->expects(self::never())->method('all');

        $ranked = (new CandidateRanker(null, null, $off))->rank([$this->model('a')], RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat);
        self::assertArrayNotHasKey('latency', $ranked[0]->signals);

        $on = $this->createMock(ModelCallHistoryInterface::class);
        $on->method('enabled')->willReturn(true);
        $on->expects(self::never())->method('all');

        (new CandidateRanker(null, null, $on))->rank([$this->model('a')], RoutingPolicyMode::PROVIDER_PRIORITY, [], ProviderOperation::Chat);
    }

    #[Test]
    public function tooFewCallsAreAnAbsenceNotAMeasurement(): void
    {
        $ranked = (new CandidateRanker(null, null, $this->history([
            'model-a' => new ModelCallStats(ModelCallStats::MIN_SAMPLES - 1, 100.0, ModelCallStats::MIN_SAMPLES - 1, 0.001),
            'model-b' => new ModelCallStats(ModelCallStats::MIN_SAMPLES, 9000.0, ModelCallStats::MIN_SAMPLES, 0.5),
        ])))->rank([$this->model('a'), $this->model('b')], RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat);

        $byId = [];
        foreach ($ranked as $candidate) {
            $byId[$candidate->modelId()] = $candidate->signals;
        }

        self::assertNull($byId['model-a']['latency']);
        self::assertEqualsWithDelta(0.5, $byId['model-b']['latency'], 0.0001, 'the only measured model sits on the neutral midpoint');
    }

    #[Test]
    public function measuredCostPerCallReplacesTheListPriceWhenEveryPricedCandidateHasIt(): void
    {
        // By list price "lean" is the cheaper model; per call it costs more,
        // because it answers at greater length.
        $ranker = new CandidateRanker(null, null, $this->history([
            'model-lean'  => new ModelCallStats(20, 1000.0, 20, 0.02),
            'model-terse' => new ModelCallStats(20, 1000.0, 20, 0.005),
        ]));
        $models = [$this->model('lean', costInput: 1.0, costOutput: 1.0), $this->model('terse', costInput: 5.0, costOutput: 5.0)];

        $listPrice = $ranker->rank($models, RoutingPolicyMode::ECONOMY, []);
        self::assertSame(['lean', 'terse'], $this->ids($listPrice));

        $ranked = $ranker->rank($models, RoutingPolicyMode::ECONOMY, [], ProviderOperation::Chat);

        self::assertSame(['terse', 'lean'], $this->ids($ranked));
        self::assertArrayNotHasKey('cost', $ranked[0]->signals, 'two bases are never mixed in one decision');
        self::assertEqualsWithDelta(2 / 3, $ranked[0]->signals['callCost'], 0.0001);
        self::assertEqualsWithDelta(1 / 3, $ranked[1]->signals['callCost'], 0.0001);
    }

    #[Test]
    public function theListPriceStaysTheBasisWhileAPricedCandidateHasNoMeasuredCost(): void
    {
        $ranked = (new CandidateRanker(null, null, $this->history([
            'model-measured' => new ModelCallStats(20, 1000.0, 20, 0.001),
        ])))->rank(
            [$this->model('measured', costInput: 50.0, costOutput: 50.0), $this->model('new', costInput: 1.0, costOutput: 1.0)],
            RoutingPolicyMode::ECONOMY,
            [],
            ProviderOperation::Chat,
        );

        self::assertSame(['new', 'measured'], $this->ids($ranked));
        self::assertArrayNotHasKey('callCost', $ranked[0]->signals);
        self::assertNotNull($ranked[0]->signals['cost']);
    }

    #[Test]
    public function anUnpricedModelWithoutCostHistoryDoesNotBlockTheMeasuredBasis(): void
    {
        $ranked = (new CandidateRanker(null, null, $this->history([
            'model-a' => new ModelCallStats(20, 1000.0, 20, 0.003),
            'model-b' => new ModelCallStats(20, 1000.0, 20, 0.002),
        ])))->rank(
            [$this->model('a'), $this->model('b'), $this->model('local', costInput: 0.0, costOutput: 0.0)],
            RoutingPolicyMode::ECONOMY,
            [],
            ProviderOperation::Chat,
        );

        // The unmeasured local model is neither promoted nor demoted: it sits on
        // the neutral midpoint, between the cheaper and the dearer measured one.
        self::assertSame(['b', 'local', 'a'], $this->ids($ranked));
        self::assertNull($ranked[1]->signals['callCost']);
    }

    #[Test]
    public function aModelThatCostNothingPerCallIsTheCheapest(): void
    {
        $ranked = (new CandidateRanker(null, null, $this->history([
            'model-paid' => new ModelCallStats(20, 1000.0, 20, 0.004),
            'model-free' => new ModelCallStats(20, 1000.0, 20, 0.0),
        ])))->rank([$this->model('paid'), $this->model('free')], RoutingPolicyMode::ECONOMY, [], ProviderOperation::Chat);

        self::assertSame(['free', 'paid'], $this->ids($ranked));
        self::assertEqualsWithDelta(1.0, $ranked[0]->signals['callCost'], 0.0001);
        self::assertEqualsWithDelta(0.5, $ranked[1]->signals['callCost'], 0.0001);
    }

    #[Test]
    public function measuredCostIsOnlyCollectedWhereTheModeWeighsCost(): void
    {
        $ranked = (new CandidateRanker(null, null, $this->history([
            'model-a' => new ModelCallStats(20, 1000.0, 20, 0.004),
        ])))->rank([$this->model('a')], RoutingPolicyMode::BALANCED, [], ProviderOperation::Chat);

        self::assertArrayNotHasKey('callCost', $ranked[0]->signals);
        self::assertArrayNotHasKey('cost', $ranked[0]->signals);
    }

    /**
     * @param array<string, ModelCallStats> $byModel model id => stats; the
     *                                               fake answers only for the
     *                                               openai adapter and chat
     */
    private function history(array $byModel): ModelCallHistoryInterface
    {
        $byKey = [];
        foreach ($byModel as $model => $stats) {
            $byKey[ModelCallStats::key('openai', $model, 'chat')] = $stats;
        }

        return $this->historyByKey($byKey);
    }

    /**
     * @param array<string, ModelCallStats> $byKey keyed by ModelCallStats::key()
     */
    private function historyByKey(array $byKey): ModelCallHistoryInterface
    {
        return new class ($byKey) implements ModelCallHistoryInterface {
            /**
             * @param array<string, ModelCallStats> $byKey
             */
            public function __construct(private readonly array $byKey) {}

            public function enabled(): bool
            {
                return true;
            }

            public function all(): array
            {
                return $this->byKey;
            }
        };
    }

    /**
     * @param array<string, float> $scores
     */
    private function quality(array $scores): ModelQualityScoreProviderInterface
    {
        $stub = self::createStub(ModelQualityScoreProviderInterface::class);
        $stub->method('getQualityScore')->willReturnCallback(
            static fn (string $modelId): ?float => $scores[$modelId] ?? null,
        );

        return $stub;
    }

    /**
     * @param list<RoutingCandidate> $ranked
     *
     * @return list<string>
     */
    private function ids(array $ranked): array
    {
        return array_map(static fn (RoutingCandidate $c): string => str_replace('model-', '', $c->modelId()), $ranked);
    }

    private function model(
        string $name,
        int $providerPriority = 50,
        float $costInput = 10.0,
        float $costOutput = 10.0,
        bool $isDefault = false,
        string $providerIdentifier = 'openai',
        string $adapterType = 'openai',
    ): Model {
        $provider = new Provider();
        $provider->setIdentifier($providerIdentifier);
        $provider->setAdapterType($adapterType);
        $provider->setPriority($providerPriority);

        $model = new Model();
        $model->setIdentifier('model-' . $name);
        $model->setModelId('model-' . $name);
        $model->setName($name);
        $model->setCapabilities('chat');
        $model->setContextLength(8000);
        $model->setCostInput($costInput);
        $model->setCostOutput($costOutput);
        $model->setIsDefault($isDefault);
        $model->setProvider($provider);

        return $model;
    }
}
