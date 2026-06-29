<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
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

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->stateRepository = new ToolStateRepository($connectionPool);
    }

    #[Test]
    public function enabledNamesUsesToolDefaultsWhenNoOverrides(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', 'ok', true),
            new FakeTool('raw_tool', 'ok', false),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository);

        self::assertSame(['safe_tool'], $service->enabledNames());
    }

    #[Test]
    public function overrideEnablesADefaultDisabledToolAndDisablesADefaultEnabledOne(): void
    {
        $registry = new ToolRegistry([
            new FakeTool('safe_tool', 'ok', true),
            new FakeTool('raw_tool', 'ok', false),
        ]);
        $service = new ToolAvailabilityService($registry, $this->stateRepository);

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
        $service = new ToolAvailabilityService($registry, $this->stateRepository);

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
}
