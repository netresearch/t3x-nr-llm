<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Queue;

use Netresearch\NrLlm\Service\Agent\Queue\WorkerClock;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What a stale clock does to a worker's writes, and that the worker's clock
 * undoes it: the record's `tstamp` and the `sys_history` row of a write carry
 * `EXEC_TIME`, which TYPO3 reads once at boot.
 */
#[CoversClass(WorkerClock::class)]
final class WorkerClockTest extends AbstractFunctionalTestCase
{
    use RegistersTheFailingHookTrait;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;
        $this->importFixture('BeUsers.csv');
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'slug' => '/',
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 2, 'pid' => 1, 'colPos' => 0, 'CType' => 'text', 'header' => 'Before']);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aWriteAfterALongWaitCarriesTheBootTimeUnlessTheClockIsSet(): void
    {
        $stale = time() - 1800;
        $this->setTheGlobals($stale);
        $this->write('Written on the stale clock');

        self::assertSame($stale, $this->stampOfTheRecord(), 'positive control: a stale clock stamps the record with the boot time');
        self::assertSame($stale, $this->stampOfTheHistory(), 'and the history row');

        $this->setTheGlobals($stale);
        WorkerClock::set();
        $this->write('Written after the clock was set');

        self::assertEqualsWithDelta(time(), $this->stampOfTheRecord(), 5);
        self::assertEqualsWithDelta(time(), $this->stampOfTheHistory(), 5);
    }

    private function setTheGlobals(int $time): void
    {
        foreach (['EXEC_TIME', 'SIM_EXEC_TIME', 'ACCESS_TIME', 'SIM_ACCESS_TIME'] as $global) {
            $GLOBALS[$global] = $time;
        }
    }

    private function write(string $header): void
    {
        $user        = $this->useASessionlessAmbientUser(1);
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [2 => ['header' => $header]]], [], $user);
        $dataHandler->process_datamap();
    }

    private function stampOfTheRecord(): int
    {
        $value = $this->connectionPool->getConnectionForTable('tt_content')->select(['tstamp'], 'tt_content', ['uid' => 2])->fetchOne();

        return is_numeric($value) ? (int) $value : -1;
    }

    private function stampOfTheHistory(): int
    {
        $query = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $value = $query->selectLiteral('MAX(tstamp)')->from('sys_history')->executeQuery()->fetchOne();

        return is_numeric($value) ? (int) $value : -1;
    }
}
