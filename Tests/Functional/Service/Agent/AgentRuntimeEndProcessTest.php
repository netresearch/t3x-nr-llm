<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Ending a guided process on the user's request (ADR-214 item 10, ADR-228):
 * the initiator or an administrator ends it, a waiting run is cancelled
 * through the guarded transition, applied writes stay, and the run records
 * that it released the process, so a continuation no longer carries it on.
 *
 * Against the database, so "cancelled" and "released" are read back from the
 * stored row and its lineage events.
 */
#[CoversClass(AgentRuntime::class)]
final class AgentRuntimeEndProcessTest extends AbstractFunctionalTestCase
{
    private const OWNER = 2;

    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function theInitiatorEndsAWaitingProcessRun(): void
    {
        $handle = $this->waitingProcessRun();

        $result = $this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), $handle->uuid);

        self::assertTrue($result->cancelled);
        self::assertSame(AgentRunStatus::CANCELLED, $result->status);
        self::assertSame(AgentRunStatus::CANCELLED, $this->storedRun($handle->uuid)->statusEnum());
        $lineage = $this->lineage($handle->uuid);
        self::assertTrue($lineage->released);
        self::assertFalse($lineage->holdsProcess());
        self::assertSame('pages:1', (string)$lineage->subject?->record, 'the subject stays on record');
    }

    /**
     * A run that already ended keeps its status; only the release is
     * recorded, so the next turn starts without the process.
     */
    #[Test]
    public function theInitiatorEndsTheProcessOfAFinishedRun(): void
    {
        $handle = $this->processRun();
        self::assertTrue($this->persister->settleCancelled($handle));

        $result = $this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), $handle->uuid);

        self::assertTrue($result->cancelled);
        self::assertSame(AgentRunStatus::CANCELLED, $result->status);
        self::assertTrue($this->lineage($handle->uuid)->released);
    }

    /**
     * An administrator ends a stuck process as the initiator does.
     */
    #[Test]
    public function anAdministratorEndsAProcess(): void
    {
        $handle = $this->waitingProcessRun();

        $result = $this->runtime()->endProcess(AiActorContext::backendUser(1, isAdmin: true), $handle->uuid);

        self::assertTrue($result->cancelled);
        self::assertSame(AgentRunStatus::CANCELLED, $this->storedRun($handle->uuid)->statusEnum());
        self::assertTrue($this->lineage($handle->uuid)->released);
    }

    /**
     * Nobody else: not another editor, not the holder of the approve grant,
     * not a service account. They learn nothing about the run, and it keeps
     * waiting with its process.
     */
    #[Test]
    public function nobodyElseEndsAProcess(): void
    {
        foreach ([
            'another editor'     => AiActorContext::backendUser(3),
            'an approve grant'   => AiActorContext::backendUser(3, grants: [BackendUserGrant::AGENT_APPROVE]),
            'a service account'  => AiActorContext::serviceAccount('nightly', [ServiceAccountScope::AGENT_APPROVE]),
        ] as $who => $actor) {
            $handle = $this->waitingProcessRun();

            $result = $this->runtime()->endProcess($actor, $handle->uuid);

            self::assertFalse($result->cancelled, $who);
            self::assertNull($result->status, $who . ' learns nothing');
            self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->storedRun($handle->uuid)->statusEnum(), $who);
            self::assertTrue($this->lineage($handle->uuid)->holdsProcess(), $who);
        }

        $unknown = $this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), '00000000-0000-4000-8000-000000000000');
        self::assertFalse($unknown->cancelled);
        self::assertNull($unknown->status);
    }

    /**
     * A running turn is not interrupted from here: the caller waits for it to
     * pause or end and asks again.
     */
    #[Test]
    public function aRunningTurnIsNotEnded(): void
    {
        $handle = $this->processRun();

        $result = $this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), $handle->uuid);

        self::assertFalse($result->cancelled);
        self::assertSame(AgentRunStatus::RUNNING, $result->status);
        self::assertTrue($this->lineage($handle->uuid)->holdsProcess());
    }

    /**
     * Without a process this is not the call that ends a run: a waiting run
     * without one, or whose process was released, is left as it is.
     */
    #[Test]
    public function aRunWithoutAProcessIsLeftAlone(): void
    {
        $plain = $this->persister->begin(null, self::OWNER);
        self::assertInstanceOf(AgentRunHandle::class, $plain);
        self::assertTrue($this->persister->suspend($plain, $this->state()));

        $result = $this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), $plain->uuid);
        self::assertFalse($result->cancelled);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $result->status);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->storedRun($plain->uuid)->statusEnum());

        $released = $this->waitingProcessRun();
        self::assertTrue($this->persister->recordLineage($released, $this->lineage($released->uuid)->withReleasedProcess()));
        self::assertFalse($this->runtime()->endProcess(AiActorContext::backendUser(self::OWNER), $released->uuid)->cancelled);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->storedRun($released->uuid)->statusEnum());
    }

    // --- helpers -----------------------------------------------------------

    private function processRun(): AgentRunHandle
    {
        $handle = $this->persister->begin(null, self::OWNER);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        self::assertTrue($this->persister->recordLineage($handle, new RunLineage(
            30,
            new SkillPin(5, 10, str_repeat('a', 64)),
            new SubjectRecord(new RecordReference('pages', 1)),
        )));

        return $handle;
    }

    private function waitingProcessRun(): AgentRunHandle
    {
        $handle = $this->processRun();
        self::assertTrue($this->persister->suspend($handle, $this->state()));

        return $handle;
    }

    private function state(): SuspendedRunState
    {
        return new SuspendedRunState([], [ToolCall::function('c1', 'touch_thing', ['uid' => 42])->toArray()], 1, 0, 0);
    }

    private function storedRun(string $uuid): AgentRun
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function lineage(string $uuid): RunLineage
    {
        $lineage = $this->persister->lineageOf($this->storedRun($uuid));
        self::assertInstanceOf(RunLineage::class, $lineage);

        return $lineage;
    }

    private function runtime(): AgentRuntime
    {
        return new AgentRuntime(
            self::createStub(ToolLoopServiceInterface::class),
            $this->persister,
            self::createStub(LlmConfigurationRepository::class),
            new NullLogger(),
        );
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
