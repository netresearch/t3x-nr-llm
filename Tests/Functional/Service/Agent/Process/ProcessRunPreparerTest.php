<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Exception\SkillSectionNotAdmittedException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessVerdictUnavailableException;
use Netresearch\NrLlm\Service\Agent\Process\ApprovedProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\Process\PreparedProcess;
use Netresearch\NrLlm\Service\Agent\Process\ProcessRunPreparer;
use Netresearch\NrLlm\Service\Agent\Process\SubjectRecordAccess;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillRecordLookup;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * The start checks of a run that invokes or continues a process (ADR-214
 * items 6 and 10, ADR-228), against the database: real approvals, real skill
 * rows, real runs and their lineage events.
 *
 * Every guard is asserted in both directions: the refusal, and the case one
 * condition away that passes. BeUsers.csv: uid 1 is an administrator, uid 2
 * an editor who starts the runs here.
 */
#[CoversClass(ProcessRunPreparer::class)]
final class ProcessRunPreparerTest extends AbstractFunctionalTestCase
{
    use ApprovedSkillsTrait;

    private const SOURCE = 10;

    private const OWNER = 2;

    private const PROCESS = 5;

    private const OTHER_PROCESS = 6;

    private const CONFIGURATION = 30;

    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        // The editor (uid 2) is in group 7, which mounts page 1 and reads
        // pages in the default language.
        $this->pool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'               => 7,
            'pid'               => 0,
            'title'             => 'Editors',
            'tables_select'     => 'pages,tt_content',
            'db_mountpoints'    => '1',
            'allowed_languages' => '0',
        ]);
        $this->pool()->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => self::OWNER]);

        $this->seedSkillSource(self::SOURCE);
        $this->pool()->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home', 'perms_everybody' => 1]);

        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function aRunThatNeitherInvokesNorContinuesIsLeftAlone(): void
    {
        $handle   = $this->begin();
        $prepared = $this->preparer()->prepare($this->request($this->configuration($this->processSkill())), $this->actingUser(self::OWNER), $handle);

        self::assertNull($prepared->pin);
        self::assertNull($prepared->subject);
        self::assertSame([], $prepared->dropped);
        self::assertNull($this->lineage($handle->uuid), 'nothing recorded');
    }

    #[Test]
    public function anInvocationPinsTheApprovedVersionAndRecordsTheLineage(): void
    {
        $skill   = $this->processSkill();
        $handle  = $this->begin();
        $subject = new SubjectRecord(new RecordReference('pages', 1));

        $prepared = $this->preparer()->prepare(
            $this->request($this->configuration($skill), new SkillInvocation(self::PROCESS, $subject)),
            $this->actingUser(self::OWNER),
            $handle,
        );

        self::assertEquals(new SkillPin(self::PROCESS, self::SOURCE, $skill->getVersionDigest()), $prepared->pin);
        self::assertEquals($subject, $prepared->subject);
        self::assertTrue($prepared->newStart);

        $lineage = $this->lineage($handle->uuid);
        self::assertInstanceOf(RunLineage::class, $lineage);
        self::assertEquals($prepared->pin, $lineage->processPin);
        self::assertEquals($subject, $lineage->subject);
        self::assertSame(self::CONFIGURATION, $lineage->configurationUid);
        self::assertFalse($lineage->released);
        self::assertSame('10:skills/tour-5/SKILL.md', $lineage->processSkill, 'the status names it');
    }

    /**
     * Who may use the configuration decides first; a member of its group may.
     */
    #[Test]
    public function anInvocationNeedsTheConfiguration(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $configuration->setBeGroups($this->beGroups(8));

        $this->assertRefused(
            ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED,
            $this->request($configuration, new SkillInvocation(self::PROCESS)),
        );

        $configuration->setBeGroups($this->beGroups(7));
        $prepared = $this->prepare($this->request($configuration, new SkillInvocation(self::PROCESS)));
        self::assertInstanceOf(SkillPin::class, $prepared->pin);
    }

    /**
     * The checks read the acting user as it is now, not the actor the request
     * froze (ADR-228): a queued request that claims a group or the admin flag
     * its user no longer has gets neither.
     */
    #[Test]
    public function theChecksReadTheActingUserNotTheFrozenActor(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $configuration->setBeGroups($this->beGroups(9));
        $this->assertRefused(
            ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED,
            $this->request($configuration, new SkillInvocation(self::PROCESS), AiActorContext::backendUser(self::OWNER, backendGroupIds: [9])),
        );

        $adminsRun = $this->finishedProcessRun($this->configuration($this->processSkill()), initiator: 1);
        $this->assertRefused(
            ProcessInvocationRefusal::PREDECESSOR_MISSING,
            $this->request($this->configuration($this->processSkill()), null, AiActorContext::backendUser(self::OWNER, isAdmin: true), $adminsRun),
        );

        // With another acting user than the actor, nothing the actor claims
        // is granted.
        try {
            $this->preparer()->prepare(
                $this->request($configuration, new SkillInvocation(self::PROCESS), AiActorContext::backendUser(self::OWNER, backendGroupIds: [9])),
                $this->actingUser(1),
                $this->begin(),
            );
            self::fail('Expected a refusal for another acting user');
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame(ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED, $exception->reason);
        }
    }

    #[Test]
    public function anInvocationNeedsTheSkillAttached(): void
    {
        $configuration = $this->configuration($this->processSkill());

        $this->assertRefused(ProcessInvocationRefusal::SKILL_NOT_ATTACHED, $this->request($configuration, new SkillInvocation(99)));
        self::assertInstanceOf(SkillPin::class, $this->prepare($this->request($configuration, new SkillInvocation(self::PROCESS)))->pin);
    }

    #[Test]
    public function anInvocationNeedsTheSkillEnabledAndNotOrphaned(): void
    {
        $disabled = $this->processSkill();
        $disabled->setEnabled(false);
        $this->assertRefused(ProcessInvocationRefusal::SKILL_DISABLED, $this->request($this->configuration($disabled), new SkillInvocation(self::PROCESS)));

        $orphaned = $this->processSkill();
        $orphaned->setOrphaned(true);
        $this->assertRefused(ProcessInvocationRefusal::SKILL_DISABLED, $this->request($this->configuration($orphaned), new SkillInvocation(self::PROCESS)));
    }

    #[Test]
    public function anInvocationNeedsAnApprovedInstructionMarkedAProcess(): void
    {
        $unapproved = $this->skill(self::PROCESS, process: true, approve: false);
        $this->assertRefused(ProcessInvocationRefusal::NOT_AN_INSTRUCTION, $this->request($this->configuration($unapproved), new SkillInvocation(self::PROCESS)));

        $guide = $this->skill(self::OTHER_PROCESS, process: false);
        $this->assertRefused(ProcessInvocationRefusal::NOT_A_PROCESS, $this->request($this->configuration($guide), new SkillInvocation(self::OTHER_PROCESS)));
    }

    #[Test]
    public function aServiceAccountCannotRunAProcess(): void
    {
        $this->assertRefused(
            ProcessInvocationRefusal::SERVICE_ACCOUNT,
            $this->request($this->configuration($this->processSkill()), new SkillInvocation(self::PROCESS), AiActorContext::serviceAccount('nightly', [ServiceAccountScope::CONFIGURATION_USE])),
        );
    }

    #[Test]
    public function aFourEyesConfigurationRefusesANewProcess(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $configuration->setRequireSecondApprover(true);

        $this->assertRefused(ProcessInvocationRefusal::SECOND_APPROVER_REQUIRED, $this->request($configuration, new SkillInvocation(self::PROCESS)));
    }

    /**
     * A continued process on a configuration that turned four-eyes on since
     * stops with the stop's own exception, not a refusal to start.
     */
    #[Test]
    public function aFourEyesConfigurationStopsAContinuedProcess(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $predecessor   = $this->finishedProcessRun($configuration);
        $configuration->setRequireSecondApprover(true);

        $this->expectException(ProcessRunNeedsSecondApproverException::class);
        $this->prepare($this->request($configuration, null, predecessor: $predecessor));
    }

    #[Test]
    public function theActingUserMustBeAbleToReadTheSubjectRecord(): void
    {
        $configuration = $this->configuration($this->processSkill());

        $this->assertRefused(
            ProcessInvocationRefusal::SUBJECT_NOT_READABLE,
            $this->request($configuration, new SkillInvocation(self::PROCESS, new SubjectRecord(new RecordReference('pages', 999)))),
        );

        try {
            $this->preparer()->prepare(
                $this->request($configuration, new SkillInvocation(self::PROCESS, new SubjectRecord(new RecordReference('pages', 1)))),
                null,
                $this->begin(),
            );
            self::fail('Expected a refusal without an acting user');
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame(ProcessInvocationRefusal::SUBJECT_NOT_READABLE, $exception->reason);
        }

        $prepared = $this->prepare($this->request($configuration, new SkillInvocation(self::PROCESS, new SubjectRecord(new RecordReference('pages', 1)))));
        self::assertSame('pages:1', (string)$prepared->subject?->record);
    }

    #[Test]
    public function aProcessWhoseLineageCannotBeStoredIsRefused(): void
    {
        try {
            $this->preparer()->prepare($this->request($this->configuration($this->processSkill()), new SkillInvocation(self::PROCESS)), $this->actingUser(self::OWNER), null);
            self::fail('Expected a refusal without a run row');
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame(ProcessInvocationRefusal::LINEAGE_NOT_STORED, $exception->reason);
        }
    }

    /**
     * The continuation derives the process, its pin and its subject from the
     * predecessor: the request carries no pin, and the new run records the
     * same lineage.
     */
    #[Test]
    public function aContinuationCarriesThePredecessorsProcessOn(): void
    {
        $skill         = $this->processSkill();
        $configuration = $this->configuration($skill);
        $subject       = new SubjectRecord(new RecordReference('pages', 1), 0);
        $predecessor   = $this->finishedProcessRun($configuration, $subject);

        $handle   = $this->begin();
        $prepared = $this->preparer()->prepare($this->request($configuration, null, predecessor: $predecessor), $this->actingUser(self::OWNER), $handle);

        self::assertEquals(new SkillPin(self::PROCESS, self::SOURCE, $skill->getVersionDigest()), $prepared->pin);
        self::assertEquals($subject, $prepared->subject);
        self::assertFalse($prepared->newStart, 'continued, not admitted again');
        self::assertEquals($prepared->pin, $this->lineage($handle->uuid)?->processPin);
    }

    /**
     * Invoking the skill the predecessor already holds is the same process,
     * not a second start; invoking another one is refused while it is held.
     */
    #[Test]
    public function aHeldProcessIsContinuedNotReplaced(): void
    {
        $configuration = $this->configuration($this->processSkill(), $this->skill(self::OTHER_PROCESS, process: true));
        $predecessor   = $this->finishedProcessRun($configuration);

        $same = $this->prepare($this->request($configuration, new SkillInvocation(self::PROCESS), predecessor: $predecessor));
        self::assertSame(self::PROCESS, $same->pin?->skillUid);
        self::assertFalse($same->newStart, 'the same process, not a new start');

        $this->assertRefused(ProcessInvocationRefusal::PROCESS_HELD, $this->request($configuration, new SkillInvocation(self::OTHER_PROCESS), predecessor: $predecessor));
    }

    #[Test]
    public function aMissingPredecessorIsRefused(): void
    {
        $this->assertRefused(
            ProcessInvocationRefusal::PREDECESSOR_MISSING,
            $this->request($this->configuration($this->processSkill()), null, predecessor: '00000000-0000-4000-8000-000000000000'),
        );
    }

    /**
     * Only the initiator or an administrator continues a run; another editor
     * may not derive its instructions and subject record.
     */
    #[Test]
    public function onlyTheInitiatorOrAnAdministratorContinuesARun(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $predecessor   = $this->finishedProcessRun($configuration);

        $this->assertRefused(
            ProcessInvocationRefusal::PREDECESSOR_MISSING,
            $this->request($configuration, null, AiActorContext::backendUser(3), $predecessor),
        );

        self::assertSame(self::PROCESS, $this->prepare($this->request($configuration, null, AiActorContext::backendUser(1, isAdmin: true), $predecessor))->pin?->skillUid);
        self::assertSame(self::PROCESS, $this->prepare($this->request($configuration, null, predecessor: $predecessor))->pin?->skillUid);
    }

    #[Test]
    public function aPredecessorHoldingAProcessMustBeFinished(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $running       = $this->begin($configuration);
        self::assertTrue($this->persister->recordLineage($running, new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS))));

        $this->assertRefused(ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED, $this->request($configuration, null, predecessor: $running->uuid));

        self::assertTrue($this->persister->settleCancelled($running));
        self::assertSame(self::PROCESS, $this->prepare($this->request($configuration, null, predecessor: $running->uuid))->pin?->skillUid);
    }

    /**
     * A predecessor without a process is an ordinary conversation: it may be
     * running, and nothing is carried on.
     */
    #[Test]
    public function aPredecessorWithoutAProcessCarriesNothing(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $running       = $this->begin($configuration);

        $prepared = $this->prepare($this->request($configuration, null, predecessor: $running->uuid));
        self::assertNull($prepared->pin);
        self::assertSame([], $prepared->dropped);

        $released = $this->begin($configuration);
        self::assertTrue($this->persister->recordLineage($released, (new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS)))->withReleasedProcess()));
        self::assertNull($this->prepare($this->request($configuration, null, predecessor: $released->uuid))->pin, 'a released process is not carried on');
    }

    #[Test]
    public function aTurnOnAnotherConfigurationDropsTheProcessWithANotice(): void
    {
        $predecessor = $this->finishedProcessRun($this->configuration($this->processSkill()));
        $elsewhere   = $this->configuration($this->processSkill());
        $elsewhere->_setProperty('uid', self::CONFIGURATION + 1);

        $prepared = $this->prepare($this->request($elsewhere, null, predecessor: $predecessor));

        self::assertNull($prepared->pin);
        self::assertCount(1, $prepared->dropped);
        self::assertSame(DroppedSourceReason::CONFIGURATION_CHANGED, $prepared->dropped[0]->reason);
        self::assertSame(self::PROCESS, $prepared->dropped[0]->uid);
    }

    #[Test]
    public function aDetachedSkillDropsTheProcessWithANotice(): void
    {
        $predecessor = $this->finishedProcessRun($this->configuration($this->processSkill()));

        $prepared = $this->prepare($this->request($this->configuration(), null, predecessor: $predecessor));

        self::assertNull($prepared->pin);
        self::assertCount(1, $prepared->dropped);
        self::assertSame(DroppedSourceReason::DETACHED, $prepared->dropped[0]->reason);
    }

    /**
     * A pin that no longer holds stops the turn, and the new run records its
     * lineage without the process, so the turn after it goes on without it
     * instead of failing on it for ever.
     */
    #[Test]
    public function aRevokedPinStopsTheTurnAndIsLeftBehind(): void
    {
        $skill         = $this->processSkill();
        $configuration = $this->configuration($skill);
        $predecessor   = $this->finishedProcessRun($configuration);
        $this->approvalService()->revokeVersion($skill, $skill->getVersionDigest(), 1);

        $handle = $this->begin();
        try {
            $this->preparer()->prepare($this->request($configuration, null, predecessor: $predecessor), $this->actingUser(self::OWNER), $handle);
            self::fail('Expected a refusal');
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame(ProcessInvocationRefusal::PIN_WITHDRAWN, $exception->reason);
            self::assertStringContainsString('revoked', $exception->getMessage());
        }

        $lineage = $this->lineage($handle->uuid);
        self::assertInstanceOf(RunLineage::class, $lineage);
        self::assertFalse($lineage->holdsProcess());

        self::assertTrue($this->persister->settleCancelled($handle));
        self::assertNull($this->prepare($this->request($configuration, null, predecessor: $handle->uuid))->pin, 'the next turn goes on without it');
    }

    /**
     * A store that cannot answer is no withdrawn approval (ADR-228): a start
     * and a continuation whose approvals cannot be read throw the retryable
     * refusal and record no lineage, so the run is tried again rather than
     * stopped or left without its process.
     */
    #[Test]
    public function anApprovalStoreThatCannotBeReadIsRetriedNotRefused(): void
    {
        $skill         = $this->processSkill();
        $configuration = $this->configuration($skill);
        $predecessor   = $this->finishedProcessRun($configuration);
        $start         = $this->request($configuration, new SkillInvocation(self::PROCESS));
        $continuation  = $this->request($configuration, null, predecessor: $predecessor);
        $onPage        = $this->request($configuration, new SkillInvocation(self::PROCESS, new SubjectRecord(new RecordReference('pages', 1))));

        foreach ([
            'approvals, a start'        => ['tx_nrllm_skill_approval', $start],
            'approvals, a continuation' => ['tx_nrllm_skill_approval', $continuation],
            'sources, a start'          => ['tx_nrllm_skill_source', $start],
            'sources, a continuation'   => ['tx_nrllm_skill_source', $continuation],
            'the subject record'        => ['pages', $onPage],
        ] as $case => [$table, $request]) {
            $handle     = $this->begin();
            $actingUser = $this->actingUser(self::OWNER);
            $preparer   = $this->preparer();
            $connection = $this->pool()->getConnectionForTable($table);
            $connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO %s_away', $table, $table));
            try {
                $preparer->prepare($request, $actingUser, $handle);
                self::fail($case . ': expected the retryable refusal');
            } catch (ProcessVerdictUnavailableException) {
                // Expected: nothing was decided.
            } finally {
                $connection->executeStatement(sprintf('ALTER TABLE %s_away RENAME TO %s', $table, $table));
            }

            self::assertNull($this->lineage($handle->uuid), $case . ': no lineage is recorded');
            self::assertInstanceOf(PreparedProcess::class, $this->preparer()->prepare($request, $this->actingUser(self::OWNER), $this->begin()), $case . ': the retry goes through');
        }
    }

    /**
     * A pinned process whose record an administrator disabled stops the
     * continuation, as it stops a resume: the pin check treats a disabled
     * record like an orphaned one. ADR-214 item 10 waives `enabled` for a
     * continuation; ADR-228 records why this stream keeps the stricter rule.
     */
    #[Test]
    public function aDisabledPinnedProcessStopsTheContinuation(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $predecessor   = $this->finishedProcessRun($configuration);
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['enabled' => 0], ['uid' => self::PROCESS]);

        $this->expectException(ProcessInvocationRefusedException::class);
        $this->expectExceptionMessage('disabled');
        $this->prepare($this->request($configuration, null, predecessor: $predecessor));
    }

    /**
     * The trust floor the catalogue applies (ADR-061) holds for a start too:
     * a skill the composer does not admit is refused, one it admits starts.
     */
    #[Test]
    public function anInvocationNeedsTheSkillAdmittedByTheTrustFloor(): void
    {
        $skill = $this->processSkill();
        try {
            $this->preparer(['minTrustLevel' => 'verified'])->prepare(
                $this->request($this->configuration($skill), new SkillInvocation(self::PROCESS)),
                $this->actingUser(self::OWNER),
                $this->begin(),
            );
            self::fail('Expected a refusal below the trust floor');
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame(ProcessInvocationRefusal::SKILL_NOT_ADMITTED, $exception->reason);
        }

        $skill->setTrustLevel('verified');
        $prepared = $this->preparer(['minTrustLevel' => 'verified'])->prepare(
            $this->request($this->configuration($skill), new SkillInvocation(self::PROCESS)),
            $this->actingUser(self::OWNER),
            $this->begin(),
        );
        self::assertInstanceOf(SkillPin::class, $prepared->pin);
    }

    /**
     * A predecessor whose lineage cannot be read refuses the continuation
     * with its own reason, rather than continuing without the process.
     */
    #[Test]
    public function aPredecessorWhoseLineageCannotBeReadIsRefused(): void
    {
        $handle = $this->begin();
        self::assertTrue($this->persister->settleCancelled($handle));
        $this->pool()->getConnectionForTable('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', [
            'pid' => 0, 'run' => $handle->runUid, 'sequence' => 0, 'kind' => 'lineage', 'payload' => '{}', 'crdate' => time(),
        ]);

        $this->assertRefused(ProcessInvocationRefusal::LINEAGE_UNREADABLE, $this->request($this->configuration($this->processSkill()), null, predecessor: $handle->uuid));
    }

    /**
     * A predecessor that never sent a provider call — here refused by the
     * context window — never admitted the process, so the continuation
     * admits it as a start (ADR-214 item 7); a predecessor that sent hands
     * on a process the conversation holds.
     */
    #[Test]
    public function aPredecessorTheWindowRefusedHandsItsProcessOnForAdmission(): void
    {
        $configuration = $this->configuration($this->processSkill());

        $refused = $this->persister->begin($configuration, self::OWNER);
        self::assertInstanceOf(AgentRunHandle::class, $refused);
        self::assertTrue($this->persister->recordLineage($refused, new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS), originRunUuid: $refused->uuid)));
        self::assertTrue($this->persister->settleFailed($refused, new SkillSectionNotAdmittedException(['tour'], 1)));

        self::assertTrue($this->prepare($this->request($configuration, null, predecessor: $refused->uuid))->newStart);
        self::assertFalse($this->prepare($this->request($configuration, null, predecessor: $this->finishedProcessRun($configuration)))->newStart);
    }

    /**
     * Every turn of a process records the run that started it: a start names
     * itself, a continuation carries its predecessor's origin on.
     */
    #[Test]
    public function everyTurnRecordsTheRunThatStartedTheProcess(): void
    {
        $configuration = $this->configuration($this->processSkill());

        $started = $this->begin();
        $this->preparer()->prepare($this->request($configuration, new SkillInvocation(self::PROCESS)), $this->actingUser(self::OWNER), $started);
        self::assertSame($started->uuid, $this->lineage($started->uuid)?->originRunUuid);
        self::assertTrue($this->persister->recordStep($started, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 0.0)));
        self::assertTrue($this->persister->settleCancelled($started));

        $second = $this->begin();
        $this->preparer()->prepare($this->request($configuration, null, predecessor: $started->uuid), $this->actingUser(self::OWNER), $second);
        self::assertSame($started->uuid, $this->lineage($second->uuid)?->originRunUuid);
        self::assertTrue($this->persister->settleCancelled($second));

        $third = $this->begin();
        $this->preparer()->prepare($this->request($configuration, null, predecessor: $second->uuid), $this->actingUser(self::OWNER), $third);
        self::assertSame($started->uuid, $this->lineage($third->uuid)?->originRunUuid);
    }

    /**
     * Once the run that started the process released it, no turn of the
     * conversation carries it on, a parallel one included.
     */
    #[Test]
    public function aProcessWhoseStartWasReleasedIsNotCarriedOn(): void
    {
        $configuration = $this->configuration($this->processSkill());
        $origin        = $this->finishedProcessRun($configuration);
        $turn          = $this->finishedProcessRun($configuration, origin: $origin);

        self::assertSame(self::PROCESS, $this->prepare($this->request($configuration, null, predecessor: $turn))->pin?->skillUid);

        $originRun = $this->persister->findRun($origin);
        self::assertInstanceOf(AgentRun::class, $originRun);
        $handle = $this->persister->resumeHandle($originRun);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        self::assertTrue($this->persister->recordLineage($handle, (new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS), originRunUuid: $origin))->withReleasedProcess()));

        self::assertNull($this->prepare($this->request($configuration, null, predecessor: $turn))->pin);
    }

    /**
     * On resume the subject is checked again with the rule of the start: a
     * record the acting user can no longer read is not carried.
     */
    #[Test]
    public function aResumedSubjectIsCheckedAgain(): void
    {
        $subject = new SubjectRecord(new RecordReference('pages', 1));

        self::assertSame($subject, $this->preparer()->readableSubject($this->actingUser(self::OWNER), $subject));
        self::assertNull($this->preparer()->readableSubject(null, $subject));
        self::assertNull($this->preparer()->readableSubject($this->actingUser(self::OWNER), new SubjectRecord(new RecordReference('pages', 999))));
        self::assertNull($this->preparer()->readableSubject($this->actingUser(self::OWNER), null));
    }

    // --- helpers -----------------------------------------------------------

    private function assertRefused(ProcessInvocationRefusal $reason, AgentRunRequest $request): void
    {
        try {
            $this->prepare($request);
            self::fail('Expected a refusal: ' . $reason->name);
        } catch (ProcessInvocationRefusedException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function prepare(AgentRunRequest $request): PreparedProcess
    {
        return $this->preparer()->prepare($request, $this->actingUser($request->actor->backendUserUid), $this->begin());
    }

    /**
     * The backend user the runtime would resolve for an actor: the fixture's
     * administrator or editor, none for any other uid.
     */
    private function actingUser(int $uid): ?BackendUserAuthentication
    {
        return in_array($uid, [1, self::OWNER], true) ? $this->setUpBackendUser($uid) : null;
    }

    /**
     * @return ObjectStorage<BackendUserGroup>
     */
    private function beGroups(int $uid): ObjectStorage
    {
        $group = new BackendUserGroup();
        $group->_setProperty('uid', $uid);
        /** @var ObjectStorage<BackendUserGroup> $groups */
        $groups = new ObjectStorage();
        $groups->attach($group);

        return $groups;
    }

    private function request(LlmConfiguration $configuration, ?SkillInvocation $invocation = null, ?AiActorContext $actor = null, string $predecessor = ''): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user('go')],
            actor: $actor ?? AiActorContext::backendUser(self::OWNER),
            invocation: $invocation,
            predecessorRunUuid: $predecessor,
        );
    }

    private function begin(?LlmConfiguration $configuration = null): AgentRunHandle
    {
        $handle = $this->persister->begin($configuration, self::OWNER);
        self::assertInstanceOf(AgentRunHandle::class, $handle);

        return $handle;
    }

    /**
     * @return string the uuid of a cancelled run that holds the process
     */
    /**
     * A turn that held the process, sent a provider call and ended; the
     * process started with it unless `$origin` names an earlier run.
     */
    private function finishedProcessRun(LlmConfiguration $configuration, ?SubjectRecord $subject = null, int $initiator = self::OWNER, string $origin = ''): string
    {
        $handle = $this->persister->begin($configuration, $initiator);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        self::assertTrue($this->persister->recordLineage($handle, new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS), $subject, originRunUuid: $origin !== '' ? $origin : $handle->uuid)));
        self::assertTrue($this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 0.0)));
        self::assertTrue($this->persister->settleCancelled($handle));

        return $handle->uuid;
    }

    private function pinOf(int $skillUid): SkillPin
    {
        $approval = (new SkillApprovalRepository($this->pool()))->findLatestUnrevoked($skillUid);
        self::assertNotNull($approval);

        return new SkillPin($approval->skillUid, $approval->sourceUid, $approval->versionDigest);
    }

    private function lineage(string $uuid): ?RunLineage
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $this->persister->lineageOf($run);
    }

    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', self::CONFIGURATION);
        $configuration->setIdentifier('cfg-process');
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    private function processSkill(): Skill
    {
        return $this->skill(self::PROCESS, process: true);
    }

    private function skill(int $uid, bool $process, bool $approve = true): Skill
    {
        return $this->approvedSkill($uid, self::SOURCE, $process, $approve);
    }

    /**
     * @param array<string, string> $skills the `skills` extension settings
     */
    private function preparer(array $skills = []): ProcessRunPreparer
    {
        $factory = $this->composerFactory($skills);

        return new ProcessRunPreparer(
            $this->persister,
            new ConfigurationResolver(),
            $factory->create(),
            new SkillPinCheck(new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()), new SkillRecordLookup($this->pool()), $factory),
            new ApprovedProcessPinProbe(new SkillApprovalRepository($this->pool())),
            new SubjectRecordAccess($this->pool(), new TableReadAccessService()),
        );
    }
}
