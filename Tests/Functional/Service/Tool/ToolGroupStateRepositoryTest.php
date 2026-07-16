<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ToolGroupStateRepository against the
 * tx_nrllm_tool_group_state table — the per-GROUP sibling of
 * {@see ToolStateRepositoryTest} with the same upsert semantics:
 * no row means "enabled", the first setEnabled() inserts, repeats
 * update in place, and overrides() reflects the persisted booleans.
 */
#[CoversClass(ToolGroupStateRepository::class)]
final class ToolGroupStateRepositoryTest extends AbstractFunctionalTestCase
{
    private ToolGroupStateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ToolGroupStateRepository($this->getService(ConnectionPool::class));
    }

    #[Test]
    public function freshStoreHasNoOverrides(): void
    {
        self::assertSame([], $this->repository->overrides());
    }

    #[Test]
    public function setEnabledInsertsThenReflectsInOverrides(): void
    {
        $this->repository->setEnabled('diagnostics', true);
        $this->repository->setEnabled('rag', false);

        $overrides = $this->repository->overrides();

        self::assertTrue($overrides['diagnostics'] ?? null);
        self::assertFalse($overrides['rag'] ?? null);
    }

    #[Test]
    public function repeatedSetEnabledUpdatesInPlaceWithoutDuplicateRow(): void
    {
        $this->repository->setEnabled('fal', true);
        // Same value again — must not create a second row.
        $this->repository->setEnabled('fal', true);
        // Then flip it.
        $this->repository->setEnabled('fal', false);

        $overrides = $this->repository->overrides();
        self::assertArrayHasKey('fal', $overrides);
        self::assertFalse($overrides['fal']);

        $rowCount = $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_tool_group_state')
            ->count('uid', 'tx_nrllm_tool_group_state', ['group_name' => 'fal']);
        self::assertSame(1, $rowCount);
    }

    #[Test]
    public function overridesSkipRowsWithEmptyGroupName(): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_tool_group_state')
            ->insert('tx_nrllm_tool_group_state', [
                'pid'        => 0,
                'group_name' => '',
                'enabled'    => 1,
            ]);
        $this->repository->setEnabled('rag', true);

        self::assertSame(['rag' => true], $this->repository->overrides());
    }
}
