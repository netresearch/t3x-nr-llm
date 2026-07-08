<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
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

        self::assertSame([
            ['name' => 'alpha', 'enabled' => true, 'overridden' => false],
            ['name' => 'beta', 'enabled' => false, 'overridden' => true],
        ], $service->groupStates());
    }
}
