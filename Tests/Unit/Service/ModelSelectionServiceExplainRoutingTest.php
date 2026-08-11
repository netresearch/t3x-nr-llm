<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\ModelSelectionService;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
use Netresearch\NrLlm\Service\Routing\EligibilityEvaluator;
use Netresearch\NrLlm\Service\Routing\RoutingDecisionService;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The operator-facing readout of a model selection (ADR-148).
 *
 * The trap this class exists to pin down is the fixed-mode answer: a
 * configuration whose model was named by the operator must be reported as NO
 * decision, not as a decision with one candidate. The rest asserts that the
 * readout reports the same enforcement and mode the resolution would have used.
 */
#[CoversClass(ModelSelectionService::class)]
final class ModelSelectionServiceExplainRoutingTest extends TestCase
{
    #[Test]
    public function aFixedConfigurationIsReportedAsNoDecision(): void
    {
        $named  = $this->model('named');
        $config = $this->fixedConfiguration($named);

        $readout = $this->subject([$this->model('other')])->explainRouting($config, ProviderOperation::Chat, null);

        self::assertTrue($readout->fixed);
        self::assertNull($readout->decision, 'fixed mode chooses nothing, so there is no decision to show');
        self::assertNull($readout->getMode(), 'and no policy mode was consulted');
        self::assertNull($readout->operationCapabilityEnforcing);
        self::assertNull($readout->operationSelected, 'an operation was named, but nothing was decided for it to bear on');
        self::assertSame($named, $readout->namedModel);
        self::assertTrue($readout->hasSelection());
    }

    #[Test]
    public function aFixedConfigurationWithoutAModelIsStillNotADecision(): void
    {
        $readout = $this->subject([$this->model('other')])
            ->explainRouting($this->fixedConfiguration(null), null, null);

        self::assertTrue($readout->fixed);
        self::assertNull($readout->decision);
        self::assertFalse($readout->hasSelection());
    }

    #[Test]
    public function aCriteriaConfigurationCarriesTheRankedAndTheRefusedCandidates(): void
    {
        $readout = $this->subject([
            $this->model('openai'),
            $this->model('local', adapterType: 'ollama'),
        ])->explainRouting($this->criteriaConfiguration(['adapterTypes' => ['openai']]), null, null);

        self::assertFalse($readout->fixed);
        self::assertTrue($readout->hasSelection());

        $decision = $readout->decision;
        self::assertNotNull($decision);
        self::assertSame('model-openai', $decision->selected?->getModelId());
        self::assertCount(1, $decision->eligibleCandidates());
        self::assertSame(
            [RoutingRejectionReason::ADAPTER_NOT_ALLOWED],
            array_map(
                static fn(RoutingCandidate $c): ?RoutingRejectionReason => $c->rejectionReason,
                $decision->rejectedCandidates(),
            ),
        );
    }

    #[Test]
    public function anEmptyCatalogueIsDistinctFromEveryCandidateBeingRefused(): void
    {
        // The two need opposite fixes, so the readout must not fold them into
        // one "nothing selected".
        $empty = $this->subject([])->explainRouting($this->criteriaConfiguration([]), null, null);
        self::assertFalse($empty->hasSelection());
        self::assertTrue($empty->isEmptyCatalogue());

        $refused = $this->subject([$this->model('local', adapterType: 'ollama')])
            ->explainRouting($this->criteriaConfiguration(['adapterTypes' => ['openai']]), null, null);
        self::assertFalse($refused->hasSelection());
        self::assertFalse($refused->isEmptyCatalogue());

        $decision = $refused->decision;
        self::assertNotNull($decision);
        self::assertCount(1, $decision->rejectedCandidates());
    }

    #[Test]
    public function enforcementOnConstrainsTheDecisionAndTheReadoutSaysSo(): void
    {
        $readout = $this->subject([$this->model('chatonly', capabilities: 'chat')])
            ->explainRouting($this->criteriaConfiguration([]), ProviderOperation::Tools, null);

        self::assertTrue($readout->operationCapabilityEnforcing);
        self::assertSame(ModelCapability::TOOLS, $readout->requiredCapability);
        self::assertFalse($readout->hasSelection());

        $decision = $readout->decision;
        self::assertNotNull($decision);
        self::assertSame(
            [RoutingRejectionReason::OPERATION_CAPABILITY_MISSING],
            array_map(
                static fn(RoutingCandidate $c): ?RoutingRejectionReason => $c->rejectionReason,
                $decision->rejectedCandidates(),
            ),
        );
    }

    #[Test]
    public function observeModeLeavesTheOperationCapabilityOutOfTheDecision(): void
    {
        // Observing must not silently look like enforcing: the same model is
        // selected, and the readout names the axis as observed.
        $readout = $this->subject(
            [$this->model('chatonly', capabilities: 'chat')],
            ['routing' => ['operationCapabilityEnforcement' => 'observe']],
        )->explainRouting($this->criteriaConfiguration([]), ProviderOperation::Tools, null);

        self::assertFalse($readout->operationCapabilityEnforcing);
        self::assertSame(ModelCapability::TOOLS, $readout->requiredCapability);
        self::assertTrue($readout->hasSelection());

        $decision = $readout->decision;
        self::assertNotNull($decision);
        self::assertSame([], $decision->rejectedCandidates());
    }

    #[Test]
    public function anOperationThatConstrainsNothingReportsNoRequiredCapability(): void
    {
        $readout = $this->subject([$this->model('a')])
            ->explainRouting($this->criteriaConfiguration([]), ProviderOperation::Embedding, null);

        self::assertNull($readout->requiredCapability);
        self::assertTrue($readout->hasSelection());
        // Both causes of a null capability arrive here; the readout keeps them
        // apart so the page does not report on an unchosen operation.
        self::assertTrue($readout->operationSelected);
    }

    #[Test]
    public function noOperationIsDistinctFromAnOperationThatConstrainsNothing(): void
    {
        $readout = $this->subject([$this->model('a')])
            ->explainRouting($this->criteriaConfiguration([]), null, null);

        self::assertNull($readout->requiredCapability);
        self::assertFalse($readout->operationSelected);
    }

    #[Test]
    public function aTriedPolicyModeIsMarkedAsSuchAndTheConfiguredOneIsUnchanged(): void
    {
        $subject = $this->subject([$this->model('a')], ['routing' => ['policyMode' => 'providerPriority']]);

        $tried = $subject->explainRouting($this->criteriaConfiguration([]), null, RoutingPolicyMode::QUALITY);
        self::assertSame(RoutingPolicyMode::QUALITY, $tried->getMode());
        self::assertTrue($tried->modeOverridden);

        $asRun = $subject->explainRouting($this->criteriaConfiguration([]), null, null);
        self::assertSame(RoutingPolicyMode::PROVIDER_PRIORITY, $asRun->getMode());
        self::assertFalse($asRun->modeOverridden);
    }

    /**
     * @param list<Model>          $models
     * @param array<string, mixed> $extensionConfiguration
     */
    private function subject(array $models, array $extensionConfiguration = []): ModelSelectionService
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($extensionConfiguration);

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findActive')->willReturn(new InMemoryQueryResult($models));

        return new ModelSelectionService(
            $repository,
            $configuration,
            null,
            // Injected rather than left to the fallback: production autowires
            // the decision point, and a hand-built one silently drops the
            // quality and health providers.
            new RoutingDecisionService($repository, new EligibilityEvaluator(), new CandidateRanker(), $configuration),
            new EligibilityEvaluator(),
        );
    }

    private function fixedConfiguration(?Model $model): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('fixed-config');
        $configuration->setModelSelectionMode(LlmConfiguration::SELECTION_MODE_FIXED);
        if ($model instanceof Model) {
            $configuration->setLlmModel($model);
        }

        return $configuration;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function criteriaConfiguration(array $criteria): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('criteria-config');
        $configuration->setModelSelectionMode(LlmConfiguration::SELECTION_MODE_CRITERIA);
        $configuration->setModelSelectionCriteria(json_encode($criteria, JSON_THROW_ON_ERROR));

        return $configuration;
    }

    private function model(string $name, string $capabilities = 'chat,tools', string $adapterType = 'openai'): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('provider-' . $name);
        $provider->setAdapterType($adapterType);
        $provider->setPriority(50);

        $model = new Model();
        $model->setIdentifier('model-' . $name);
        $model->setModelId('model-' . $name);
        $model->setName($name);
        $model->setCapabilities($capabilities);
        $model->setContextLength(8000);
        $model->setProvider($provider);

        return $model;
    }
}
