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
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
use Netresearch\NrLlm\Service\Routing\EligibilityEvaluator;
use Netresearch\NrLlm\Service\Routing\RoutingDecisionService;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(RoutingDecisionService::class)]
final class RoutingDecisionServiceTest extends TestCase
{
    #[Test]
    public function theSelectedModelIsTheHighestRankedEligibleCandidate(): void
    {
        $decision = $this->subject([
            $this->model('low', providerPriority: 10),
            $this->model('high', providerPriority: 90),
        ])->decide([]);

        self::assertTrue($decision->hasSelection());
        self::assertSame('model-high', $decision->selected?->getModelId());
    }

    #[Test]
    public function aRejectedCandidateCarriesItsReasonAndNoScore(): void
    {
        // The invariant the eligibility/ranking split exists for: a model
        // refused by a hard constraint has no number to win with.
        $decision = $this->subject([
            $this->model('openai'),
            $this->model('local', adapterType: 'ollama'),
        ])->decide(['adapterTypes' => ['openai']]);

        self::assertSame('model-openai', $decision->selected?->getModelId());

        $rejected = $decision->rejectedCandidates();
        self::assertCount(1, $rejected);
        self::assertSame(RoutingRejectionReason::ADAPTER_NOT_ALLOWED, $rejected[0]->rejectionReason);
        self::assertNull($rejected[0]->score);
        self::assertSame([], $rejected[0]->signals);
    }

    #[Test]
    public function everyConsideredModelAppearsInTheDecision(): void
    {
        // An operator asking "why not that one" must find it in the answer.
        $decision = $this->subject([
            $this->model('a'),
            $this->model('b', adapterType: 'ollama'),
            $this->model('c', adapterType: 'ollama'),
        ])->decide(['adapterTypes' => ['openai']]);

        self::assertCount(3, $decision->candidates);
        self::assertCount(1, $decision->eligibleCandidates());
        self::assertCount(2, $decision->rejectedCandidates());
    }

    #[Test]
    public function anEmptyCatalogueYieldsADecisionWithoutCandidates(): void
    {
        $decision = $this->subject([])->decide([]);

        self::assertFalse($decision->hasSelection());
        self::assertSame([], $decision->candidates);
    }

    #[Test]
    public function candidatesRejectedToTheLastStillReportWhy(): void
    {
        // Distinct from an empty catalogue: this is a configuration problem,
        // and the reasons are what say so.
        $decision = $this->subject([$this->model('a', adapterType: 'ollama')])->decide(['adapterTypes' => ['openai']]);

        self::assertFalse($decision->hasSelection());
        self::assertSame(
            [RoutingRejectionReason::ADAPTER_NOT_ALLOWED],
            array_map(static fn(RoutingCandidate $c): ?RoutingRejectionReason => $c->rejectionReason, $decision->candidates),
        );
    }

    #[Test]
    public function theConfiguredPolicyModeIsUsed(): void
    {
        $decision = $this->subject([$this->model('a')], ['routing' => ['policyMode' => 'economy']])->decide([]);

        self::assertSame(RoutingPolicyMode::ECONOMY, $decision->mode);
    }

    #[Test]
    public function anUnreadableConfigurationKeepsTheEstablishedOrdering(): void
    {
        // A broken setting must not silently change which model serves a call.
        // The conservative direction here is the behaviour that already exists,
        // not the newest feature.
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('no such extension', 1786665602));

        $service = new RoutingDecisionService(
            $this->repository([$this->model('a')]),
            new EligibilityEvaluator(),
            new CandidateRanker(),
            $configuration,
        );

        self::assertSame(RoutingPolicyMode::PROVIDER_PRIORITY, $service->decide([])->mode);
    }

    #[Test]
    public function anUnknownPolicyModeFallsBackToTheEstablishedOrdering(): void
    {
        $decision = $this->subject([$this->model('a')], ['routing' => ['policyMode' => 'cheapest-possible']])->decide([]);

        self::assertSame(RoutingPolicyMode::PROVIDER_PRIORITY, $decision->mode);
    }

    /**
     * @param list<Model>          $models
     * @param array<string, mixed> $extensionConfiguration
     */
    private function subject(array $models, array $extensionConfiguration = []): RoutingDecisionService
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($extensionConfiguration);

        return new RoutingDecisionService(
            $this->repository($models),
            new EligibilityEvaluator(),
            new CandidateRanker(),
            $configuration,
        );
    }

    /**
     * @param list<Model> $models
     */
    private function repository(array $models): ModelRepository
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findActive')->willReturn(new InMemoryQueryResult($models));

        return $repository;
    }

    private function model(string $name, int $providerPriority = 50, string $adapterType = 'openai'): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('provider-' . $name);
        $provider->setAdapterType($adapterType);
        $provider->setPriority($providerPriority);

        $model = new Model();
        $model->setIdentifier('model-' . $name);
        $model->setModelId('model-' . $name);
        $model->setName($name);
        $model->setCapabilities('chat');
        $model->setContextLength(8000);
        $model->setProvider($provider);

        return $model;
    }
}
