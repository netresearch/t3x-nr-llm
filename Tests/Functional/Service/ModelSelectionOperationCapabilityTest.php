<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\ModelSelectionService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Criteria-mode selection against a real model corpus (ADR-138).
 *
 * The unit tests pin the decision table; this one pins that the decision
 * survives the persistence round-trip — a criteria JSON read from the database,
 * a capability CSV read from a `tx_nrllm_model` row, and the repository's own
 * active/deleted filtering.
 */
#[CoversClass(ModelSelectionService::class)]
final class ModelSelectionOperationCapabilityTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $configurations;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var LlmConfigurationRepository $configurations */
        $configurations       = $this->get(LlmConfigurationRepository::class);
        $this->configurations = $configurations;

        $this->importFixture('CapabilityRouting.csv');
    }

    protected function tearDown(): void
    {
        $this->storeEnforcement(null);
        parent::tearDown();
    }

    /**
     * Write `routing.operationCapabilityEnforcement` the way an operator's
     * extension configuration would present it. Null removes the whole section.
     */
    private function storeEnforcement(?string $mode): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }

        $extensions['nr_llm'] = $mode === null
            ? []
            : ['routing' => ['operationCapabilityEnforcement' => $mode]];

        $confVars['EXTENSIONS']  = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    private function subject(): ModelSelectionService
    {
        /** @var ModelSelectionService $subject */
        $subject = $this->get(ModelSelectionService::class);

        return $subject;
    }

    private function configuration(string $identifier): LlmConfiguration
    {
        $configuration = $this->configurations->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration, 'Fixture configuration missing: ' . $identifier);
        self::assertTrue($configuration->usesCriteriaSelection(), $identifier . ' must be a criteria-mode fixture');

        return $configuration;
    }

    #[Test]
    public function aToolsCallResolvesTheToolCapableModel(): void
    {
        $this->storeEnforcement('enforce');

        $configuration = $this->configuration('routing-mixed');

        // Without the operation the corpus order wins and the chat-only model
        // is picked — that is the reported defect.
        $withoutOperation = $this->subject()->resolveModel($configuration, null);
        self::assertInstanceOf(Model::class, $withoutOperation);
        self::assertSame('routing-chat-only', $withoutOperation->getModelId());

        $forTools = $this->subject()->resolveModel($configuration, ProviderOperation::Tools);
        self::assertInstanceOf(Model::class, $forTools);
        self::assertSame('routing-with-tools', $forTools->getModelId());
    }

    #[Test]
    public function enforceModeRefusesWhenNoMatchingModelCanDoTools(): void
    {
        $this->storeEnforcement('enforce');

        $configuration = $this->configuration('routing-no-tools');

        // The same configuration still resolves for a chat call.
        $forChat = $this->subject()->resolveModel($configuration, ProviderOperation::Chat);
        self::assertInstanceOf(Model::class, $forChat);
        self::assertSame('routing-openai-chat', $forChat->getModelId());

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionCode(1786100138);

        $this->subject()->resolveModel($configuration, ProviderOperation::Tools);
    }

    #[Test]
    public function observeModeKeepsResolvingTheModelThatCannotDoTools(): void
    {
        $this->storeEnforcement('observe');

        $resolved = $this->subject()->resolveModel(
            $this->configuration('routing-no-tools'),
            ProviderOperation::Tools,
        );

        self::assertInstanceOf(Model::class, $resolved);
        self::assertSame('routing-openai-chat', $resolved->getModelId());
    }

    #[Test]
    public function anEmptyCapabilityColumnIsUndeclaredAndStillResolves(): void
    {
        // Enforce mode, a model row whose capability CSV was never filled: it
        // passes, because an absent statement is not a denial. Fail-closed here
        // would break every installation that ignored the optional field.
        $this->storeEnforcement('enforce');

        $resolved = $this->subject()->resolveModel(
            $this->configuration('routing-undeclared'),
            ProviderOperation::Tools,
        );

        self::assertInstanceOf(Model::class, $resolved);
        self::assertSame('routing-undeclared', $resolved->getModelId());
    }

    #[Test]
    public function theSettingIsReadableThroughTheRealExtensionConfiguration(): void
    {
        // Guards the wiring the three tests above depend on: the value written
        // by storeEnforcement() is what ExtensionConfiguration hands the
        // service. Without this, a rename of the setting key would leave those
        // tests silently exercising the fail-closed default instead.
        $this->storeEnforcement('observe');

        /** @var ExtensionConfiguration $extensionConfiguration */
        $extensionConfiguration = $this->get(ExtensionConfiguration::class);
        $configuration          = $extensionConfiguration->get('nr_llm');

        self::assertIsArray($configuration);
        self::assertIsArray($configuration['routing'] ?? null);
        self::assertSame('observe', $configuration['routing']['operationCapabilityEnforcement'] ?? null);
    }
}
