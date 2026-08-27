<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The manager threads the model that will serve the call into the input-context
 * trust gate (ADR-149).
 *
 * The gate itself is tested against a model handed to it; what these tests
 * cover is the half only the manager can answer — that the model reaching the
 * gate is the one routing selected, that a routing failure stays a routing
 * failure, and that fixed mode asks routing nothing at all.
 */
#[CoversClass(LlmServiceManager::class)]
final class InputContextTrustGateEntryPointTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    #[Test]
    public function aCriteriaModeSendIsJudgedAgainstTheSelectedModelsProvider(): void
    {
        // Before ADR-149 this refused: the configuration has no provider
        // relation, so the zone was EXTERNAL_GLOBAL however local the model
        // routing picked.
        $response = $this->manager($this->selecting($this->modelIn(TrustZone::LOCAL)))
            ->chatWithConfiguration([ChatMessage::user('hi')], $this->criteriaConfiguration());

        self::assertSame('ok', $response->content);
    }

    #[Test]
    public function anExternalSelectionStillRefusesTheSameSend(): void
    {
        // The control: the zone follows the selected model, it is not waived by
        // the model existing.
        $manager = $this->manager($this->selecting($this->modelIn(TrustZone::EXTERNAL_GLOBAL)));

        $this->expectException(InputContextTrustZoneException::class);
        $manager->chatWithConfiguration([ChatMessage::user('hi')], $this->criteriaConfiguration());
    }

    #[Test]
    public function routingSelectingNothingLeavesTheFailClosedZone(): void
    {
        // Routing found no model. There is no serving provider, so
        // EXTERNAL_GLOBAL stands — the same answer this path gave before the
        // model was threaded in, so nothing is newly refused.
        $manager = $this->manager($this->selecting(null));

        $this->expectException(InputContextTrustZoneException::class);
        $manager->chatWithConfiguration([ChatMessage::user('hi')], $this->criteriaConfiguration());
    }

    #[Test]
    public function aRoutingFailureIsNotSwallowedIntoTheGate(): void
    {
        // Nothing is classified here, so the gate has no opinion at all. The
        // resolution the gate needed must not have eaten the routing error:
        // what the caller sees is the dispatch failing to find a model, exactly
        // as it did before the gate resolved anything.
        $configuration = $this->criteriaConfiguration();
        $configuration->setSnippetTags('');

        $manager = $this->manager($this->selecting(null));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageIsOrContains('has no model assigned');
        $manager->chatWithConfiguration([ChatMessage::user('hi')], $configuration);
    }

    #[Test]
    public function bothResolutionsOfOneSendNameTheSameOperation(): void
    {
        // ADR-149's "one operation, one selection". Every other stub in this
        // file discards the operation, so a gate resolving under a different
        // one than the terminal — judging a model the send never runs on —
        // would leave them all green. This one looks at the argument.
        $model = $this->modelIn(TrustZone::LOCAL);
        $seen  = [];

        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModelForCall')->willReturnCallback(
            static function (LlmConfiguration $configuration, ?ProviderOperation $operation) use (&$seen, $model): ModelResolution {
                $seen[] = $operation;

                return ModelResolution::withoutDecision($model);
            },
        );

        $this->manager($selection)
            ->chatWithConfiguration([ChatMessage::user('hi')], $this->criteriaConfiguration());

        self::assertSame([ProviderOperation::Chat, ProviderOperation::Chat], $seen);
    }

    #[Test]
    public function fixedModeResolvesOnceAndOnlyForTheDispatch(): void
    {
        // Characterisation of the unchanged path. A fixed-mode configuration
        // names its model and `getProvider()` reads through that same relation,
        // so a resolution for the gate could only return what the gate already
        // had. The count is the measurement: one, the dispatch's own — the gate
        // added none.
        $resolutions = 0;
        $selection   = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModelForCall')->willReturnCallback(
            static function (LlmConfiguration $configuration) use (&$resolutions): ModelResolution {
                ++$resolutions;

                return ModelResolution::withoutDecision($configuration->getLlmModel());
            },
        );

        $response = $this->manager($selection)
            ->chatWithConfiguration([ChatMessage::user('hi')], $this->fixedConfiguration(TrustZone::LOCAL));

        self::assertSame('ok', $response->content);
        self::assertSame(1, $resolutions, 'the gate must not add a resolution in fixed mode');
    }

    #[Test]
    public function fixedModeStillRefusesAnExternalProvider(): void
    {
        // The other half of the characterisation: the fixed-mode verdict is
        // untouched in both directions.
        $manager = $this->manager(null);

        $this->expectException(InputContextTrustZoneException::class);
        $manager->chatWithConfiguration([ChatMessage::user('hi')], $this->fixedConfiguration(TrustZone::EXTERNAL_GLOBAL));
    }

    private function selecting(?Model $model): ModelSelectionServiceInterface
    {
        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModelForCall')->willReturn(ModelResolution::withoutDecision($model));

        return $selection;
    }

    private function manager(?ModelSelectionServiceInterface $selection): LlmServiceManager
    {
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturn(new CompletionResponse(
            content: 'ok',
            model: 'some-model',
            usage: new UsageStatistics(1, 1, 2),
            provider: 'some-provider',
        ));

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['providers' => []]);

        return $this->createLlmServiceManager(
            $extensionConfiguration,
            self::createStub(LoggerInterface::class),
            $registry,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            null,
            null,
            $selection,
            null,
            null,
            null,
            null,
            $this->gate(),
        );
    }

    /**
     * The real gate over a real classifier and a real resolver: the agreement
     * between them is what the manager has to feed correctly, so doubling it
     * away would leave the threading untested.
     */
    private function gate(): InputContextTrustGate
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier('legal-policy');
        $snippet->setName('legal-policy');
        $snippet->setDataClass(ToolDataClass::SECRET_ADJACENT->value);

        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn([$snippet]);

        $enforcement = self::createStub(ExtensionConfiguration::class);
        $enforcement->method('get')->willReturn([]);

        return new InputContextTrustGate(
            new InputContextClassifier(new ConfigurationSnippetResolver($repository, new PromptSnippetComposer())),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver($enforcement),
        );
    }

    private function criteriaConfiguration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified-by-criteria');
        $configuration->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        $configuration->setSnippetTags('policy');

        return $configuration;
    }

    private function fixedConfiguration(TrustZone $zone): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified');
        $configuration->setLlmModel($this->modelIn($zone));
        $configuration->setSnippetTags('policy');

        return $configuration;
    }

    private function modelIn(TrustZone $zone): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('some-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZone($zone->value);

        $model = new Model();
        $model->setModelId('some-model');
        $model->setProvider($provider);

        return $model;
    }
}
