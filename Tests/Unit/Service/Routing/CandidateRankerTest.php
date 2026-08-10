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
use Netresearch\NrLlm\Service\Evaluation\ModelQualityScoreProviderInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
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

        self::assertSame([0.5, 0.5], array_map(static fn(RoutingCandidate $c): ?float => $c->score, $ranked));
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
            static fn(string $provider): ProviderHealthScore => $provider === 'flaky'
                ? ProviderHealthScore::fromSamples('flaky', 10, 3, 100.0)
                : ProviderHealthScore::fromSamples('solid', 10, 10, 100.0),
        );

        $ranked = (new CandidateRanker(null, $health))->rank(
            [$this->model('flaky', providerIdentifier: 'flaky'), $this->model('solid', providerIdentifier: 'solid')],
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
    public function noSignalsAreCollectedInTheDefaultMode(): void
    {
        // Asking a quality store and a telemetry window per candidate costs
        // queries whose answers this mode would not read.
        $quality = $this->createMock(ModelQualityScoreProviderInterface::class);
        $quality->expects(self::never())->method('getQualityScore');

        (new CandidateRanker($quality))->rank([$this->model('a')], RoutingPolicyMode::PROVIDER_PRIORITY, []);
    }

    /**
     * @param array<string, float> $scores
     */
    private function quality(array $scores): ModelQualityScoreProviderInterface
    {
        $stub = self::createStub(ModelQualityScoreProviderInterface::class);
        $stub->method('getQualityScore')->willReturnCallback(
            static fn(string $modelId): ?float => $scores[$modelId] ?? null,
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
        return array_map(static fn(RoutingCandidate $c): string => str_replace('model-', '', $c->modelId()), $ranked);
    }

    private function model(
        string $name,
        int $providerPriority = 50,
        int $costInput = 10,
        int $costOutput = 10,
        bool $isDefault = false,
        string $providerIdentifier = 'openai',
    ): Model {
        $provider = new Provider();
        $provider->setIdentifier($providerIdentifier);
        $provider->setAdapterType('openai');
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
