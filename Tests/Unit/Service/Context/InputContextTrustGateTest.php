<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(InputContextTrustGate::class)]
final class InputContextTrustGateTest extends TestCase
{
    #[Test]
    public function anUndeclaredSnippetPlacesNoConstraint(): void
    {
        // The migration guarantee. Every snippet that existed before this field
        // did is undeclared, and an installation that classified nothing must
        // behave exactly as it did before — otherwise shipping the axis
        // enforcing would break working setups on upgrade.
        $this->gate([$this->snippet('legal', '')])
            ->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function aConfigurationThatInjectsNothingIsNeverRefused(): void
    {
        // The gate runs for every configuration-driven operation, including the
        // ones that inject no snippets and no skills at all -- vision builds a
        // transient configuration for exactly that reason. Refusing such a call
        // would block a send that does not carry the classified content.
        $bare = new LlmConfiguration();
        $bare->setIdentifier('ad-hoc:vision:default');

        $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)])
            ->assertPermitted($bare);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function aConfidentialSnippetCannotReachAnExternalProvider(): void
    {
        $gate = $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)]);

        $this->expectException(InputContextTrustZoneException::class);
        $gate->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL));
    }

    #[Test]
    public function theRefusalNamesTheSourceAndTheZoneButNeverTheText(): void
    {
        $snippet = $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value);
        $snippet->setSnippet('The merger closes on Tuesday.');

        try {
            $this->gate([$snippet])->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL));
            self::fail('Expected InputContextTrustZoneException');
        } catch (InputContextTrustZoneException $e) {
            self::assertStringContainsString('legal-policy', $e->getMessage(), 'an operator has to know WHICH source');
            self::assertStringContainsString(TrustZone::EXTERNAL_GLOBAL->value, $e->getMessage());
            self::assertStringNotContainsString('merger', $e->getMessage(), 'the content is the thing being protected');
        }
    }

    #[Test]
    public function theSameSnippetIsPermittedInATrustedZone(): void
    {
        $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)])
            ->assertPermitted($this->configuration(TrustZone::LOCAL));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function theStrictestDeclarationDecides(): void
    {
        // One confidential source makes the whole send confidential, whatever
        // it travels with.
        $gate = $this->gate([
            $this->snippet('tone', ToolDataClass::PUBLIC_CONTENT->value),
            $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value),
        ]);

        try {
            $gate->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL));
            self::fail('Expected InputContextTrustZoneException');
        } catch (InputContextTrustZoneException $e) {
            self::assertStringContainsString('legal-policy', $e->getMessage());
            self::assertStringNotContainsString('tone', $e->getMessage());
        }
    }

    #[Test]
    public function aCriteriaModeConfigurationTakesItsZoneFromTheServingModel(): void
    {
        // The ADR-149 case. The configuration has no provider relation at all,
        // so before the serving model was threaded in this refused — a
        // criteria-mode configuration that only ever picks local models was
        // still treated as external.
        $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)])
            ->assertPermitted($this->criteriaConfiguration(), 0, $this->modelIn(TrustZone::LOCAL));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function aServingModelOnAnExternalProviderStillRefuses(): void
    {
        // The control for the test above: the zone follows the model, it is not
        // waived by having one.
        $gate = $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)]);

        $this->expectException(InputContextTrustZoneException::class);
        $gate->assertPermitted($this->criteriaConfiguration(), 0, $this->modelIn(TrustZone::EXTERNAL_GLOBAL));
    }

    #[Test]
    public function routingSelectingNothingLeavesTheFailClosedZone(): void
    {
        // A routing failure must not turn into a different answer here. With no
        // serving model there is no serving provider, so EXTERNAL_GLOBAL stands
        // — which is exactly what this path answered before ADR-149, so nothing
        // is newly refused either.
        $gate = $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)]);

        $this->expectException(InputContextTrustZoneException::class);
        // No third argument, which IS the case under test: the caller resolved
        // nothing. Spelling `null` out reads better and Rector removes it.
        $gate->assertPermitted($this->criteriaConfiguration(), 0);
    }

    #[Test]
    public function fixedModeIsUnchangedByThreadingItsOwnModelIn(): void
    {
        // Characterisation. In fixed mode the configuration's provider IS the
        // model's provider, so the third argument cannot move the verdict in
        // either direction — permitted stays permitted, refused stays refused.
        $trusted    = $this->configuration(TrustZone::LOCAL);
        $external   = $this->configuration(TrustZone::EXTERNAL_GLOBAL);
        $classified = fn(): InputContextTrustGate => $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)]);

        $classified()->assertPermitted($trusted);
        $classified()->assertPermitted($trusted, 0, $trusted->getLlmModel());

        $refusals = 0;
        foreach ([null, $external->getLlmModel()] as $servingModel) {
            try {
                $classified()->assertPermitted($external, 0, $servingModel);
            } catch (InputContextTrustZoneException) {
                ++$refusals;
            }
        }

        self::assertSame(2, $refusals, 'the fixed-mode verdict is the same with and without the model threaded in');
    }

    #[Test]
    public function theAuditRowNamesTheProviderTheZoneWasReadFrom(): void
    {
        // A criteria-mode row used to carry an empty provider and model, which
        // made "blocked at EXTERNAL_GLOBAL" impossible to check against
        // anything.
        $recorded = [];

        try {
            $this->gate([$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)], events: $this->recordingEvents($recorded))
                ->assertPermitted($this->criteriaConfiguration(), 7, $this->modelIn(TrustZone::EXTERNAL_GLOBAL));
        } catch (InputContextTrustZoneException) {
            // The row it wrote is what is asserted.
        }

        self::assertCount(1, $recorded);
        self::assertSame('some-provider', $recorded[0]->provider);
        self::assertSame('some-model', $recorded[0]->model);
    }

    #[Test]
    public function observeModeRecordsTheRefusalAndLetsTheCallThrough(): void
    {
        $recorded = [];
        $gate     = $this->gate(
            [$this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value)],
            enforcing: false,
            events: $this->recordingEvents($recorded),
        );

        $gate->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL), 7);

        self::assertCount(1, $recorded);
        self::assertSame(GovernanceDecision::CONTEXT_BLOCKED->value, $recorded[0]->decision);
        self::assertStringContainsString('(observe)', $recorded[0]->detail);
    }

    #[Test]
    public function theAuditRecordsTheSourceAndTheClassButNoContent(): void
    {
        $recorded = [];
        $snippet  = $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value);
        $snippet->setSnippet('The merger closes on Tuesday.');

        try {
            $this->gate([$snippet], events: $this->recordingEvents($recorded))
                ->assertPermitted($this->configuration(TrustZone::EXTERNAL_GLOBAL), 7);
        } catch (InputContextTrustZoneException) {
            // The refusal is the point; the row it wrote is what is asserted.
        }

        self::assertCount(1, $recorded);
        self::assertSame(ToolDataClass::SECRET_ADJACENT->value, $recorded[0]->reason);
        self::assertSame(7, $recorded[0]->beUser);
        self::assertStringContainsString('legal-policy', $recorded[0]->detail);
        self::assertStringNotContainsString('merger', $recorded[0]->detail);
    }

    /**
     * @param list<GovernanceEvent> $sink
     */
    private function recordingEvents(array &$sink): GovernanceEventRepositoryInterface
    {
        $repository = self::createStub(GovernanceEventRepositoryInterface::class);
        $repository->method('record')->willReturnCallback(
            static function (GovernanceEvent $event) use (&$sink): void {
                $sink[] = $event;
            },
        );

        return $repository;
    }

    /**
     * @param list<PromptSnippet> $snippets
     */
    private function gate(
        array $snippets,
        bool $enforcing = true,
        ?GovernanceEventRepositoryInterface $events = null,
    ): InputContextTrustGate {
        // The REAL resolver over a repository double: its selection logic is
        // exactly what the gate must agree with, so doubling it away would
        // leave the agreement untested.
        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn($snippets);
        $snippetResolver = new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(
            $enforcing ? [] : ['tools' => ['dataClassEnforcement' => 'observe']],
        );

        return new InputContextTrustGate(
            new InputContextClassifier($snippetResolver),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver($extensionConfiguration),
            $events,
        );
    }

    private function snippet(string $identifier, string $dataClass): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier($identifier);
        $snippet->setName($identifier);
        $snippet->setDataClass($dataClass);

        return $snippet;
    }

    private function configuration(TrustZone $zone): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified');
        $configuration->setLlmModel($this->modelIn($zone));
        $configuration->setSnippetTags('policy');

        return $configuration;
    }

    /**
     * A criteria-mode configuration: model_uid = 0, so no model relation and no
     * provider — the record whose zone only the resolved model can supply.
     */
    private function criteriaConfiguration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified-by-criteria');
        $configuration->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        $configuration->setSnippetTags('policy');

        return $configuration;
    }

    /**
     * The zone reaches a configuration through its model's provider —
     * LlmConfiguration has no provider of its own.
     */
    private function modelIn(TrustZone $zone): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('some-provider');
        $provider->setTrustZone($zone->value);

        $model = new Model();
        $model->setModelId('some-model');
        $model->setProvider($provider);

        return $model;
    }
}
