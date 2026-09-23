<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\UnavailableToolsResolver;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRemoteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * NEXT-167, demo conversation 104: with the developer tools held back by the
 * configuration's tool groups, the user asked which tools they may NOT use and
 * the model answered that it saw no such list. The resolver is that list.
 */
#[CoversClass(UnavailableToolsResolver::class)]
final class UnavailableToolsResolverTest extends TestCase
{
    #[Test]
    public function everyGateThatRefusesAToolReportsItWithItsReason(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('get_page_content', 'ok', true, false, 'content'),
            new FakeTool('create_page_draft', 'ok', true, false, 'editing'),
            new FakeTool('get_env_raw', 'ok', true, true, 'system'),
            new FakeTool('read_source', 'ok', true, false, 'code'),
        ]);
        $configuration = $this->configuration(TrustZone::LOCAL);
        $configuration->setAllowedToolGroups('content,editing,system');

        $resolver = $this->resolver($registry, enabled: ['get_page_content', 'get_env_raw', 'read_source']);

        self::assertSame(
            [
                'create_page_draft' => ToolDenialReason::TOOL_DISABLED,
                'get_env_raw'       => ToolDenialReason::REQUIRES_ADMIN,
                'read_source'       => ToolDenialReason::CONFIGURATION_GROUP,
            ],
            $this->byName($resolver->unavailable($configuration, $this->editor())),
        );
    }

    #[Test]
    public function aToolThatIsOfferedIsNotListed(): void
    {
        $registry = new ToolRegistry([new FakeTool('get_page_content', 'ok', true, false, 'content')]);

        self::assertSame(
            [],
            $this->resolver($registry)->unavailable($this->configuration(TrustZone::LOCAL), $this->admin()),
        );
    }

    /**
     * The trust-zone gate has two modes. Enforcing, the tool is withheld and
     * listed; observing, it is still offered, so it is available and must not
     * be reported as missing.
     */
    #[Test]
    public function aTrustZoneRefusalIsListedOnlyWhileTheGateEnforces(): void
    {
        $registry      = new ToolRegistry([new FakeTool('fetch_logs', 'ok', true, false, 'system')]);
        $configuration = $this->configuration(TrustZone::EXTERNAL_GLOBAL);

        self::assertSame(
            ['fetch_logs' => ToolDenialReason::TRUST_ZONE],
            $this->byName($this->resolver($registry, enforcement: 'enforce')->unavailable($configuration, $this->admin())),
        );
        self::assertSame(
            [],
            $this->resolver($registry, enforcement: 'observe')->unavailable($configuration, $this->admin()),
        );
    }

    /**
     * A remote (MCP) tool's name is operator configuration. It is listed for an
     * administrator, never for an editor; builtins are listed for both.
     */
    #[Test]
    public function aRemoteToolIsListedForAnAdministratorOnly(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('read_source', 'ok', true, false, 'code'),
            new FakeRemoteTool('mcp_deepwiki_ask_question', 'code'),
        ]);
        $configuration = $this->configuration(TrustZone::LOCAL);
        $configuration->setAllowedToolGroups('content');
        $resolver = $this->resolver($registry);

        self::assertSame(
            ['read_source' => ToolDenialReason::CONFIGURATION_GROUP],
            $this->byName($resolver->unavailable($configuration, $this->editor())),
        );
        self::assertSame(
            ['read_source' => ToolDenialReason::CONFIGURATION_GROUP],
            $this->byName($resolver->unavailable($configuration, null)),
        );
        self::assertSame(
            [
                'read_source'               => ToolDenialReason::CONFIGURATION_GROUP,
                'mcp_deepwiki_ask_question' => ToolDenialReason::CONFIGURATION_GROUP,
            ],
            $this->byName($resolver->unavailable($configuration, $this->admin())),
        );
    }

    /**
     * Observe mode never covers a remote tool (ADR-115), so above the ceiling
     * it is listed with trustZone in either mode — for an administrator.
     */
    #[Test]
    public function aRemoteToolAboveTheCeilingIsListedEvenInObserveMode(): void
    {
        $registry      = new ToolRegistry([new FakeRemoteTool('mcp_logs_fetch', 'system')]);
        $configuration = $this->configuration(TrustZone::EXTERNAL_GLOBAL);

        self::assertSame(
            ['mcp_logs_fetch' => ToolDenialReason::TRUST_ZONE],
            $this->byName($this->resolver($registry, enforcement: 'observe')->unavailable($configuration, $this->admin())),
        );
    }

    /**
     * @param list<ToolPolicyDecision> $decisions
     *
     * @return array<string, ToolDenialReason>
     */
    private function byName(array $decisions): array
    {
        $out = [];
        foreach ($decisions as $decision) {
            self::assertFalse($decision->allowed);
            $out[$decision->toolName] = $decision->reason;
        }

        return $out;
    }

    /**
     * @param list<string>|null $enabled null = every registered tool is enabled
     */
    private function resolver(ToolRegistry $registry, ?array $enabled = null, string $enforcement = 'enforce'): UnavailableToolsResolver
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['tools' => ['dataClassEnforcement' => $enforcement]]);

        return new UnavailableToolsResolver(
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($enabled ?? $registry->names()),
                new AllowedToolsResolver(new SkillComposer(), $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver($extensionConfiguration),
            ),
        );
    }

    private function configuration(TrustZone $zone): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum($zone);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function admin(): BackendUserAuthentication
    {
        $user       = new BackendUserAuthentication();
        $user->user = ['uid' => 1, 'admin' => 1];

        return $user;
    }

    private function editor(): BackendUserAuthentication
    {
        $user       = new BackendUserAuthentication();
        $user->user = ['uid' => 2, 'admin' => 0];

        return $user;
    }
}
