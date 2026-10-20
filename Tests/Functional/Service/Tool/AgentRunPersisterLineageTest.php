<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Agent\Exception\RunLineageUnreadableException;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A run's lineage as an event in its stream (ADR-228): stored at every
 * privacy level, the latest one counts, and one that cannot be read is an
 * error rather than "no process".
 */
#[CoversClass(AgentRunPersister::class)]
final class AgentRunPersisterLineageTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theLatestLineageCountsAtEveryPrivacyLevel(): void
    {
        foreach ([PrivacyLevel::FULL, PrivacyLevel::NONE] as $level) {
            $persister = $this->persister($level);
            $handle    = $this->begin($persister);
            $first     = new RunLineage(30, new SkillPin(5, 10, str_repeat('a', 64)), new SubjectRecord(new RecordReference('pages', 1)));

            self::assertNull($persister->lineageOf($this->storedRun($persister, $handle)), $level->value . ': none yet');
            self::assertTrue($persister->recordLineage($handle, $first));
            self::assertTrue($persister->recordLineage($handle, $first->withReleasedProcess()));

            self::assertEquals($first->withReleasedProcess(), $persister->lineageOf($this->storedRun($persister, $handle)), $level->value);
        }
    }

    #[Test]
    public function aLineageThatCannotBeReadIsAnError(): void
    {
        $persister = $this->persister(PrivacyLevel::FULL);
        $handle    = $this->begin($persister);
        $this->pool()->getConnectionForTable('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', [
            'pid'      => 0,
            'run'      => $handle->runUid,
            'sequence' => 0,
            'kind'     => 'lineage',
            'payload'  => '{"configuration":"30"}',
            'crdate'   => time(),
        ]);

        $this->expectException(RunLineageUnreadableException::class);
        $persister->lineageOf($this->storedRun($persister, $handle));
    }

    private function persister(PrivacyLevel $level): AgentRunPersister
    {
        return new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt($level),
            new NullLogger(),
        );
    }

    private function begin(AgentRunPersister $persister): AgentRunHandle
    {
        $handle = $persister->begin(null, 2);
        self::assertInstanceOf(AgentRunHandle::class, $handle);

        return $handle;
    }

    private function storedRun(AgentRunPersister $persister, AgentRunHandle $handle): AgentRun
    {
        $run = $persister->findRun($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
