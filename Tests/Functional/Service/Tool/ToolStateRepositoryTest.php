<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ToolStateRepository against the tx_nrllm_tool_state table.
 *
 * Verifies the upsert semantics: a fresh store has no overrides, the first
 * setEnabled() inserts a row, a second on the same tool updates it in place
 * (no duplicate, even when the value is unchanged), and overrides() reflects
 * the persisted booleans.
 */
#[CoversClass(ToolStateRepository::class)]
final class ToolStateRepositoryTest extends AbstractFunctionalTestCase
{
    private ToolStateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->repository = new ToolStateRepository($connectionPool);
    }

    #[Test]
    public function freshStoreHasNoOverrides(): void
    {
        self::assertSame([], $this->repository->overrides());
    }

    #[Test]
    public function setEnabledInsertsThenReflectsInOverrides(): void
    {
        $this->repository->setEnabled('get_env_raw', true);
        $this->repository->setEnabled('list_be_users_raw', false);

        $overrides = $this->repository->overrides();

        self::assertTrue($overrides['get_env_raw'] ?? null);
        self::assertFalse($overrides['list_be_users_raw'] ?? null);
    }

    #[Test]
    public function repeatedSetEnabledUpdatesInPlaceWithoutDuplicateRow(): void
    {
        $this->repository->setEnabled('get_env_raw', true);
        // Same value again — must not create a second row.
        $this->repository->setEnabled('get_env_raw', true);
        // Then flip it.
        $this->repository->setEnabled('get_env_raw', false);

        $overrides = $this->repository->overrides();
        self::assertArrayHasKey('get_env_raw', $overrides);
        self::assertFalse($overrides['get_env_raw']);

        $rowCount = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_tool_state')
            ->count('uid', 'tx_nrllm_tool_state', ['tool_name' => 'get_env_raw']);
        self::assertSame(1, $rowCount);
    }
}
