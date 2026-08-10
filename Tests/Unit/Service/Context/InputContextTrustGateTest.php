<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
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
        $provider = new Provider();
        $provider->setIdentifier('some-provider');
        $provider->setTrustZone($zone->value);

        // The zone reaches the configuration through its model's provider —
        // LlmConfiguration has no provider of its own.
        $model = new Model();
        $model->setModelId('some-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified');
        $configuration->setLlmModel($model);
        $configuration->setSnippetTags('policy');

        return $configuration;
    }
}
