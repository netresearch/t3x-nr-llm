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
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
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

            // Steps recorded after it do not hide it: the read asks for the
            // latest lineage event, not the latest event.
            self::assertTrue($persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 0.0)));
            self::assertEquals($first->withReleasedProcess(), $persister->lineageOf($this->storedRun($persister, $handle)), $level->value . ': after a step');
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

    /**
     * A turn holds its process only while the run that started it does: a
     * released start, and a start that no longer exists, end it for the turn.
     */
    #[Test]
    public function aTurnHoldsItsProcessOnlyWhileItsStartDoes(): void
    {
        $persister = $this->persister(PrivacyLevel::FULL);
        $pin       = new SkillPin(5, 10, str_repeat('a', 64));

        $start = $this->begin($persister);
        self::assertTrue($persister->recordLineage($start, new RunLineage(30, $pin, originRunUuid: $start->uuid)));
        $turn = $this->begin($persister);
        self::assertTrue($persister->recordLineage($turn, new RunLineage(30, $pin, originRunUuid: $start->uuid)));

        self::assertInstanceOf(RunLineage::class, $persister->heldProcessOf($this->storedRun($persister, $turn)));

        self::assertTrue($persister->recordLineage($start, (new RunLineage(30, $pin, originRunUuid: $start->uuid))->withReleasedProcess()));
        self::assertNull($persister->heldProcessOf($this->storedRun($persister, $turn)), 'the start released it');

        $orphan = $this->begin($persister);
        self::assertTrue($persister->recordLineage($orphan, new RunLineage(30, $pin, originRunUuid: '00000000-0000-4000-8000-000000000000')));
        self::assertNull($persister->heldProcessOf($this->storedRun($persister, $orphan)), 'the start is gone');
    }

    #[Test]
    public function aRunSentAProviderCallOnlyWhenOneWasRecorded(): void
    {
        $persister = $this->persister(PrivacyLevel::NONE);
        $handle    = $this->begin($persister);
        self::assertFalse($persister->sentProviderCall($this->storedRun($persister, $handle)), 'no events');

        // Events of other kinds are no provider call.
        self::assertTrue($persister->recordLineage($handle, new RunLineage(30)));
        self::assertTrue($persister->recordStep($handle, new RunStep(kind: RunStep::KIND_ASSEMBLED, round: 0, durationMs: 0.0)));
        self::assertFalse($persister->sentProviderCall($this->storedRun($persister, $handle)), 'other events');

        self::assertTrue($persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 0.0)));
        self::assertTrue($persister->sentProviderCall($this->storedRun($persister, $handle)), 'recorded at every privacy level');
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
