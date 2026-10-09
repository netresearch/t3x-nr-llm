<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Hook;

use Netresearch\NrLlm\Hook\SkillDeletionGuardHook;
use Netresearch\NrLlm\Hook\SkillDisabledByHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * The user class decides as much as the user name (ADR-214 item 3): a
 * command-line user is not an interactive administrator whatever it is
 * called. And a skill already in the recycle bin may be deleted for good.
 */
#[CoversClass(SkillDisabledByHook::class)]
#[CoversClass(SkillDeletionGuardHook::class)]
final class SkillHookUserAndRecycleTest extends TestCase
{
    #[Test]
    public function aCommandLineUserCannotChangeTheEnableFlagWhateverItsName(): void
    {
        $cli = $this->createMock(CommandLineUserAuthentication::class);
        $cli->method('isAdmin')->willReturn(true);
        $cli->user = ['username' => 'scheduler-runner'];

        $fields = ['enabled' => 1];
        (new SkillDisabledByHook())->processDatamap_postProcessFieldArray('update', 'tx_nrllm_skill', 7, $fields, $this->dataHandler($cli));

        self::assertArrayNotHasKey('enabled', $fields);
    }

    #[Test]
    public function anInteractiveAdministratorChangesIt(): void
    {
        $admin = $this->createMock(BackendUserAuthentication::class);
        $admin->method('isAdmin')->willReturn(true);
        $admin->user = ['username' => 'admin'];

        $fields = ['enabled' => 1];
        (new SkillDisabledByHook())->processDatamap_postProcessFieldArray('update', 'tx_nrllm_skill', 7, $fields, $this->dataHandler($admin));

        self::assertSame(['enabled' => 1, 'disabled_by' => ''], $fields);
    }

    #[Test]
    public function aSkillAlreadyInTheRecycleBinIsNotGuarded(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('getQueryBuilderForTable');
        $handled = false;

        (new SkillDeletionGuardHook($pool))->processCmdmap_deleteAction('tx_nrllm_skill', 7, ['uid' => 7, 'deleted' => 1], $handled, self::createStub(DataHandler::class));

        self::assertFalse($handled);
    }

    private function dataHandler(BackendUserAuthentication $user): DataHandler
    {
        $dataHandler          = $this->createMock(DataHandler::class);
        $dataHandler->BE_USER = $user;

        return $dataHandler;
    }
}
