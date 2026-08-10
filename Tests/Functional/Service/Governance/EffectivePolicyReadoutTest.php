<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Governance;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyReadout;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRemoteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The readout cannot drift from the runtime (ADR-140).
 *
 * Both the tool gate and the governance view are wired from the real DI
 * container here, so they share one {@see DataClassEnforcementResolver}. The
 * assertion is that flipping ``tools.dataClassEnforcement`` moves BOTH — a view
 * that re-implemented the read would keep showing the old mode.
 */
#[CoversClass(EffectivePolicyReadout::class)]
#[CoversClass(DataClassEnforcementResolver::class)]
final class EffectivePolicyReadoutTest extends AbstractFunctionalTestCase
{
    /** @var array<string, mixed>|null */
    private ?array $extensionConfigurationBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigurationBackup = $this->readExtensionConfiguration();
    }

    protected function tearDown(): void
    {
        // The tests patch the live extension configuration; put it back so no
        // later test in this process inherits a mode it never set.
        $this->writeExtensionConfiguration($this->extensionConfigurationBackup ?? []);

        parent::tearDown();
    }

    #[Test]
    public function observeMovesTheToolGateAndTheViewTogether(): void
    {
        $registry      = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $configuration = $this->externalConfiguration();
        $policy        = $this->policyFromContainer($registry);
        $readout       = $this->getService(EffectivePolicyReadout::class);

        $this->setEnforcement('observe');

        $observed = $policy->decide('system_tool', $configuration, null);
        self::assertTrue($observed->allowed, 'observe still offers the over-ceiling tool');
        self::assertTrue($observed->observedOnly);
        self::assertSame('observe', $this->enforcementRow($readout)->value, 'the view must report observe too');

        $this->setEnforcement('enforce');

        $enforced = $policy->decide('system_tool', $configuration, null);
        self::assertFalse($enforced->allowed, 'enforce drops the over-ceiling tool');
        self::assertSame('enforce', $this->enforcementRow($readout)->value, 'the view must follow to enforce');
    }

    #[Test]
    public function aMistypedModeReadsAsEnforceInBothTheGateAndTheView(): void
    {
        // ADR-113 fail-closed. The view must show what the gate DOES, not the
        // literal setting — otherwise an operator reads "observ" and believes
        // the axis is off while it is enforcing.
        $registry      = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $configuration = $this->externalConfiguration();
        $policy        = $this->policyFromContainer($registry);
        $readout       = $this->getService(EffectivePolicyReadout::class);

        $this->setEnforcement('observ');

        self::assertFalse($policy->decide('system_tool', $configuration, null)->allowed);
        self::assertSame('enforce', $this->enforcementRow($readout)->value);
    }

    #[Test]
    public function observeNeverReachesARemoteToolAndTheRowSaysSo(): void
    {
        // ADR-115: the ceiling is enforced for every RemoteToolInterface tool
        // whatever the setting says. An operator whose MCP tool is being dropped
        // reads this page first, so the row must not claim the axis is observing.
        $registry = new ToolRegistry([
            new FakeTool('system_tool', 'ok', true, false, 'system'),
            new FakeRemoteTool('remote_tool'),
        ]);
        $configuration = $this->externalConfiguration();
        $policy        = $this->policyFromContainer($registry);
        $readout       = $this->getService(EffectivePolicyReadout::class);

        $this->setEnforcement('observe');

        self::assertTrue(
            $policy->decide('system_tool', $configuration, null)->allowed,
            'observe still offers the builtin above the ceiling',
        );

        $remote = $policy->decide('remote_tool', $configuration, null);
        self::assertFalse($remote->allowed, 'a remote tool above the ceiling is denied even in observe mode');
        self::assertFalse($remote->observedOnly);

        $row = $this->enforcementRow($readout);
        self::assertSame('observe', $row->value);
        self::assertSame(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.note.remoteAlwaysEnforced',
            $row->noteKey,
            'the row must qualify a mode the gate does not apply to remote tools',
        );
    }

    #[Test]
    public function everyRowIsAnsweredByARealResolverOnAStockInstall(): void
    {
        $rows = $this->getService(EffectivePolicyReadout::class)->rows();

        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertTrue($row->isKnown(), sprintf('%s must not read "unknown" on a stock install', $row->key));
            self::assertNotSame('', $row->reader);
        }
    }

    /**
     * The real gate, wired with the container's enforcement resolver — the same
     * object the readout was built with, which is the point of the test.
     */
    private function policyFromContainer(ToolRegistry $registry): ToolCallPolicy
    {
        return new ToolCallPolicy(
            $registry,
            new FakeToolAvailability($registry->names()),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            $this->getService(DataClassEnforcementResolver::class),
        );
    }

    private function enforcementRow(EffectivePolicyReadout $readout): EffectivePolicyRow
    {
        foreach ($readout->rows() as $row) {
            if ($row->key === 'tools.dataClassEnforcement') {
                return $row;
            }
        }

        self::fail('the readout has no tools.dataClassEnforcement row');
    }

    /**
     * Patch the one key in the live extension configuration, on top of the
     * stock template-synchronised values the install already carries.
     */
    private function setEnforcement(string $mode): void
    {
        // Force the template synchronisation so the rest of the stock
        // configuration is present and only this key differs.
        $this->getService(ExtensionConfiguration::class)->get('nr_llm');

        $nrLlm = $this->readExtensionConfiguration() ?? [];
        $tools = $nrLlm['tools'] ?? [];
        self::assertIsArray($tools);

        $tools['dataClassEnforcement'] = $mode;
        $nrLlm['tools']                = $tools;

        $this->writeExtensionConfiguration($nrLlm);
    }

    /**
     * The stored `nr_llm` extension configuration, or null when absent.
     * Narrowed step by step because the $GLOBALS shape is untyped.
     *
     * @return array<string, mixed>|null
     */
    private function readExtensionConfiguration(): ?array
    {
        $confVars   = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensions = is_array($confVars) ? ($confVars['EXTENSIONS'] ?? null) : null;
        $nrLlm      = is_array($extensions) ? ($extensions['nr_llm'] ?? null) : null;

        if (!is_array($nrLlm)) {
            return null;
        }

        /** @var array<string, mixed> $nrLlm */
        return $nrLlm;
    }

    /**
     * @param array<string, mixed> $nrLlm
     */
    private function writeExtensionConfiguration(array $nrLlm): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        self::assertIsArray($confVars);
        $extensions = $confVars['EXTENSIONS'] ?? [];
        self::assertIsArray($extensions);

        $extensions['nr_llm']       = $nrLlm;
        $confVars['EXTENSIONS']     = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    /**
     * A configuration whose provider sits in the EXTERNAL_GLOBAL trust zone, so
     * the SYSTEM_DIAGNOSTICS tool is above its ceiling and the enforcement
     * switch decides the outcome.
     */
    private function externalConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::EXTERNAL_GLOBAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
