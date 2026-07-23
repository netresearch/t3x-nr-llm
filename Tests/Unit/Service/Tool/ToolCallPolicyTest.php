<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\TrustZoneResolver;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(ToolCallPolicy::class)]
final class ToolCallPolicyTest extends TestCase
{
    #[Test]
    public function anUnregisteredToolIsRefusedWithoutConsultingAnythingElse(): void
    {
        $decision = $this->policy()->decide('no_such_tool', $this->configuration(TrustZone::LOCAL), $this->admin());

        self::assertFalse($decision->allowed);
        self::assertSame(ToolDenialReason::NOT_REGISTERED, $decision->reason);
    }

    #[Test]
    public function aGloballyDisabledToolIsRefused(): void
    {
        $registry = new ToolRegistry([new FakeTool('content_tool', 'ok', true, false, 'content')]);
        $policy   = $this->policy($registry, enabled: []);

        $decision = $policy->decide('content_tool', $this->configuration(TrustZone::LOCAL), $this->admin());

        self::assertFalse($decision->allowed);
        self::assertSame(ToolDenialReason::TOOL_DISABLED, $decision->reason);
    }

    #[Test]
    public function anAdminOnlyToolIsRefusedForANonAdminAndForNoUserAtAll(): void
    {
        $registry = new ToolRegistry([new FakeTool('system_tool', 'ok', true, true, 'system')]);
        $policy   = $this->policy($registry);
        $config   = $this->configuration(TrustZone::LOCAL);

        self::assertSame(ToolDenialReason::REQUIRES_ADMIN, $policy->decide('system_tool', $config, $this->editor())->reason);
        // No user is not an admin — fail closed.
        self::assertSame(ToolDenialReason::REQUIRES_ADMIN, $policy->decide('system_tool', $config, null)->reason);
        self::assertTrue($policy->decide('system_tool', $config, $this->admin())->allowed);
    }

    #[Test]
    public function aToolOutsideTheConfigurationsGroupsIsRefused(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('content_tool', 'ok', true, false, 'content'),
            new FakeTool('code_tool', 'ok', true, false, 'code'),
        ]);
        $policy = $this->policy($registry);

        $config = $this->configuration(TrustZone::LOCAL);
        $config->setAllowedToolGroups('content');

        self::assertTrue($policy->decide('content_tool', $config, $this->admin())->allowed);
        self::assertSame(
            ToolDenialReason::CONFIGURATION_GROUP,
            $policy->decide('code_tool', $config, $this->admin())->reason,
        );
    }

    #[Test]
    public function aToolAboveTheProvidersCeilingIsFlaggedInObserveModeAndDroppedWhenEnforcing(): void
    {
        $registry = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $config   = $this->configuration(TrustZone::EXTERNAL_GLOBAL);

        // Observe (the shipped default): the decision is made and reported, but
        // the tool is still offered, so an upgrade cannot silently strip tools.
        $observing = $this->policy($registry, enforcement: 'observe');
        $observed  = $observing->decide('system_tool', $config, $this->admin());

        self::assertTrue($observed->allowed);
        self::assertTrue($observed->observedOnly);
        self::assertSame(ToolDenialReason::TRUST_ZONE, $observed->reason);
        self::assertSame(ToolDataClass::SYSTEM_DIAGNOSTICS, $observed->dataClass);
        self::assertSame(ToolDataClass::EDITOR_CONTENT, $observed->ceiling);
        self::assertStringContainsString('observe mode', $observed->message());

        $enforcing = $this->policy($registry, enforcement: 'enforce');
        $enforced  = $enforcing->decide('system_tool', $config, $this->admin());

        self::assertFalse($enforced->allowed);
        self::assertFalse($enforced->observedOnly);
        self::assertSame(ToolDenialReason::TRUST_ZONE, $enforced->reason);

        // A local provider may receive it either way.
        self::assertTrue($enforcing->decide('system_tool', $this->configuration(TrustZone::LOCAL), $this->admin())->allowed);
    }

    #[Test]
    public function anythingOtherThanAnExplicitObserveEnforces(): void
    {
        // ADR-113 fail-closed: only a deliberate `observe` observes; a typo, an
        // empty string or any other value enforces, so a mistyped setting cannot
        // silently disable the gate. `observe` is matched case/space-insensitively.
        $registry = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $config   = $this->configuration(TrustZone::EXTERNAL_GLOBAL);

        $observeValues = ['observe', 'OBSERVE ', ' observe'];
        $enforceValues = ['', 'observ', 'enforce', 'off', 'yes'];

        foreach ($observeValues as $value) {
            self::assertTrue(
                $this->policy($registry, enforcement: $value)->decide('system_tool', $config, $this->admin())->allowed,
                sprintf('enforcement="%s" should observe (tool still offered)', $value),
            );
        }
        foreach ($enforceValues as $value) {
            self::assertFalse(
                $this->policy($registry, enforcement: $value)->decide('system_tool', $config, $this->admin())->allowed,
                sprintf('enforcement="%s" should fail closed to enforce', $value),
            );
        }
    }

    #[Test]
    public function anUnreadableOrMalformedConfigurationFailsClosedToEnforce(): void
    {
        // ADR-113: if the enforcement setting cannot be read (the extension
        // configuration throws) or is malformed (no `tools` section), the gate
        // enforces rather than silently observing.
        $registry = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $config   = $this->configuration(TrustZone::EXTERNAL_GLOBAL);

        $throwing = $this->createMock(ExtensionConfiguration::class);
        $throwing->method('get')->willThrowException(new RuntimeException('config unreadable', 1785100001));
        self::assertFalse(
            $this->policyWith($registry, $throwing)->decide('system_tool', $config, $this->admin())->allowed,
            'an unreadable configuration enforces',
        );

        $malformed = $this->createMock(ExtensionConfiguration::class);
        $malformed->method('get')->willReturn(['tools' => 'not-an-array']);
        self::assertFalse(
            $this->policyWith($registry, $malformed)->decide('system_tool', $config, $this->admin())->allowed,
            'a malformed configuration enforces',
        );
    }

    #[Test]
    public function theCheapestGateWinsSoADenialNeverLeaksTheTrustZoneAxis(): void
    {
        // A tool that is BOTH disabled AND above the ceiling reports the
        // disablement — the caller was already blocked before the zone mattered.
        $registry = new ToolRegistry([new FakeTool('system_tool', 'ok', true, false, 'system')]);
        $policy   = $this->policy($registry, enabled: [], enforcement: 'enforce');

        $decision = $policy->decide('system_tool', $this->configuration(TrustZone::EXTERNAL_GLOBAL), $this->admin());

        self::assertSame(ToolDenialReason::TOOL_DISABLED, $decision->reason);
        self::assertStringNotContainsString('trust zone', $decision->message());
    }

    #[Test]
    public function filterOfferableWithoutARequestCollapsesToTheEnabledSetNotTheRegistry(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('content_tool', 'ok', true, false, 'content'),
            new FakeTool('other_tool', 'ok', true, false, 'content'),
        ]);
        $policy = $this->policy($registry, enabled: ['content_tool']);

        self::assertSame(
            ['content_tool'],
            $policy->filterOfferable(null, $this->configuration(TrustZone::LOCAL), $this->admin()),
        );
    }

    #[Test]
    public function explainReportsOneDecisionPerRequestedToolIncludingTheRefusedOnes(): void
    {
        $registry = new ToolRegistry([new FakeTool('content_tool', 'ok', true, false, 'content')]);
        $policy   = $this->policy($registry);

        $decisions = $policy->explain(['content_tool', 'ghost_tool'], $this->configuration(TrustZone::LOCAL), $this->admin());

        self::assertCount(2, $decisions);
        self::assertTrue($decisions[0]->allowed);
        self::assertFalse($decisions[1]->allowed);
        self::assertSame('ghost_tool', $decisions[1]->toolName);
    }

    /**
     * @param list<string>|null $enabled null = every registered tool is enabled
     */
    private function policy(
        ?ToolRegistry $registry = null,
        ?array $enabled = null,
        string $enforcement = 'observe',
    ): ToolCallPolicy {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['tools' => ['dataClassEnforcement' => $enforcement]]);

        return $this->policyWith($registry, $extensionConfiguration, $enabled);
    }

    /**
     * @param list<string>|null $enabled null = every registered tool is enabled
     */
    private function policyWith(
        ?ToolRegistry $registry,
        ExtensionConfiguration $extensionConfiguration,
        ?array $enabled = null,
    ): ToolCallPolicy {
        $registry ??= new ToolRegistry([]);

        return new ToolCallPolicy(
            $registry,
            new FakeToolAvailability($enabled ?? $registry->names()),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            $extensionConfiguration,
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
