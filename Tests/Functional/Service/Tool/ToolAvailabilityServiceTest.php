<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeMalformedEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ToolAvailabilityService: effective enable state derived
 * from each tool's default and the admin overrides persisted in
 * tx_nrllm_tool_state.
 */
#[CoversClass(ToolAvailabilityService::class)]
final class ToolAvailabilityServiceTest extends AbstractFunctionalTestCase
{
    private ToolStateRepository $stateRepository;

    private ToolGroupStateRepository $groupStateRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->stateRepository      = new ToolStateRepository($connectionPool);
        $this->groupStateRepository = new ToolGroupStateRepository($connectionPool);
    }

    #[Test]
    public function enabledNamesUsesToolDefaultsWhenNoOverrides(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', 'ok', true),
            new FakeTool('raw_tool', 'ok', false),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        self::assertSame(['safe_tool'], $service->enabledNames());
    }

    #[Test]
    public function overrideEnablesADefaultDisabledToolAndDisablesADefaultEnabledOne(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', 'ok', true),
            new FakeTool('raw_tool', 'ok', false),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        $this->stateRepository->setEnabled('raw_tool', true);
        $this->stateRepository->setEnabled('safe_tool', false);

        self::assertSame(['raw_tool'], $service->enabledNames());
    }

    #[Test]
    public function statesReportsDefaultVersusOverriddenFlags(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', 'ok', true),
            new FakeTool('raw_tool', 'ok', false),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        $this->stateRepository->setEnabled('raw_tool', true);

        $states = [];
        foreach ($service->states() as $state) {
            $states[$state['name']] = $state;
        }

        self::assertFalse($states['safe_tool']['overridden']);
        self::assertTrue($states['safe_tool']['enabled']);
        self::assertTrue($states['safe_tool']['defaultEnabled']);

        self::assertTrue($states['raw_tool']['overridden']);
        self::assertTrue($states['raw_tool']['enabled']);
        self::assertFalse($states['raw_tool']['defaultEnabled']);
        self::assertSame('desc of raw_tool', $states['raw_tool']['description']);
    }

    #[Test]
    public function disabledGroupBeatsAnEnablingToolOverride(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', group: 'alpha'),
            new FakeTool('other_tool', group: 'beta'),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        // Explicitly enable the tool, then disable its group: the group wins.
        $this->stateRepository->setEnabled('safe_tool', true);
        $this->groupStateRepository->setEnabled('alpha', false);

        self::assertSame(['other_tool'], $service->enabledNames());

        $states = $service->states();
        self::assertFalse($states[0]['enabled']);
        self::assertTrue($states[0]['toolEnabled']);
        self::assertFalse($states[0]['groupEnabled']);
    }

    #[Test]
    public function unknownGroupDefaultsToEnabledAndReenablingRestoresTools(): void
    {
        $registry = new ToolRegistry([new FakeTool('safe_tool', group: 'alpha')]);
        $service  = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        // Never-toggled group: enabled.
        self::assertSame(['safe_tool'], $service->enabledNames());

        $this->groupStateRepository->setEnabled('alpha', false);
        self::assertSame([], $service->enabledNames());

        $this->groupStateRepository->setEnabled('alpha', true);
        self::assertSame(['safe_tool'], $service->enabledNames());
    }

    /**
     * What the Tools module renders instead of a wire name and model-facing
     * prose (ADR-152). Keyed by tool name, and only for the tools that declare
     * one — a plain tool has no entry rather than a null one.
     */
    #[Test]
    public function editorActionsCarriesTheDeclarationOfTheToolsThatHaveOne(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('read_tool', group: 'content'),
            new FakeEditorActionTool('write_tool'),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        $actions = $service->editorActions();

        self::assertSame(['write_tool'], array_keys($actions));
        self::assertInstanceOf(EditorAction::class, $actions['write_tool']);
        self::assertSame('nrllm-editor-action-page-metadata', $actions['write_tool']->iconIdentifier);
        self::assertSame(['pages'], $actions['write_tool']->recordTypes);
    }

    /**
     * The state rows feed {@see ToolAvailabilityService::enabledNames()}, which
     * the tool-call gate reads on every decision, so they must stay free of the
     * declaration (ADR-152).
     */
    #[Test]
    public function statesDoesNotCarryTheDeclaration(): void
    {
        $registry = new ToolRegistry([new FakeEditorActionTool('write_tool')]);
        $service  = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        $states = $service->states();

        self::assertCount(1, $states);
        self::assertArrayNotHasKey('action', $states[0]);
    }

    /**
     * A third-party tool shipping a malformed declaration is a rendering defect
     * and must never be able to abort tool calling (ADR-152). The gate does not
     * consult the declaration at all, and the module drops the decoration of
     * that one row rather than the row — or itself.
     */
    #[Test]
    public function aMalformedDeclarationNeitherBreaksTheGateNorHidesTheTool(): void
    {
        $registry = new ToolRegistry([
            new FakeMalformedEditorActionTool('broken_tool'),
            new FakeEditorActionTool('write_tool'),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        // The runtime gate: the tool is listed and offered, unaffected.
        self::assertSame(['broken_tool'], $service->enabledNames());
        self::assertContains('broken_tool', array_column($service->states(), 'name'));

        $policy = new ToolCallPolicy(
            $registry,
            $service,
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            $this->get(DataClassEnforcementResolver::class),
        );
        self::assertTrue($policy->decide('broken_tool', $this->localConfiguration(), null)->allowed);

        // The module: the broken declaration is absent, the sound one is not.
        self::assertSame(['write_tool'], array_keys($service->editorActions()));
    }

    #[Test]
    public function groupStatesNamesACuratedGroupAndLeavesAThirdPartyOneUnnamed(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('a', group: 'editing'),
            new FakeTool('b', group: 'my_ext'),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);

        $labels = array_column($service->groupStates(), 'labelKey', 'name');

        self::assertSame(ToolGroup::EDITING->labelKey(), $labels['editing']);
        self::assertNull($labels['my_ext']);
    }

    #[Test]
    public function groupStatesListsEachGroupOnceWithOverrideFlag(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('a', group: 'alpha'),
            new FakeTool('b', group: 'alpha'),
            new FakeTool('c', group: 'beta'),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository, $this->groupStateRepository);
        $this->groupStateRepository->setEnabled('beta', false);

        // labelKey is null for both: a test group is not part of the curated
        // taxonomy, and an unnamed group renders as its raw identifier (ADR-152).
        self::assertSame([
            ['name' => 'alpha', 'labelKey' => null, 'enabled' => true, 'overridden' => false],
            ['name' => 'beta', 'labelKey' => null, 'enabled' => false, 'overridden' => true],
        ], $service->groupStates());
    }

    /**
     * A configuration on a LOCAL provider — the trust-zone axis is not what
     * this class tests, so it must not be what decides the assertion.
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
