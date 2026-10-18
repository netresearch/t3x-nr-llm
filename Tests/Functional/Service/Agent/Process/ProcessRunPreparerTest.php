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
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
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

    private BackendUserAuthentication $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->admin = $this->setUpBackendUser(1);

        $this->seedSkillSource(self::SOURCE);
        $this->pool()->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home']);

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
        $prepared = $this->preparer()->prepare($this->request($this->configuration($this->processSkill())), $this->admin, $handle);

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
            $this->admin,
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
        $group         = new BackendUserGroup();
        $group->_setProperty('uid', 7);
        /** @var ObjectStorage<BackendUserGroup> $groups */
        $groups = new ObjectStorage();
        $groups->attach($group);

        $configuration->setBeGroups($groups);

        $this->assertRefused(
            ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED,
            $this->request($configuration, new SkillInvocation(self::PROCESS), AiActorContext::backendUser(self::OWNER)),
        );

        $prepared = $this->prepare($this->request($configuration, new SkillInvocation(self::PROCESS), AiActorContext::backendUser(self::OWNER, backendGroupIds: [7])));
        self::assertInstanceOf(SkillPin::class, $prepared->pin);
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
            $this->preparer()->prepare($this->request($this->configuration($this->processSkill()), new SkillInvocation(self::PROCESS)), $this->admin, null);
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
        $prepared = $this->preparer()->prepare($this->request($configuration, null, predecessor: $predecessor), $this->admin, $handle);

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
            ProcessInvocationRefusal::PREDECESSOR_NOT_PERMITTED,
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
            $this->preparer()->prepare($this->request($configuration, null, predecessor: $predecessor), $this->admin, $handle);
            self::fail('Expected SkillInstructionWithdrawnException');
        } catch (SkillInstructionWithdrawnException $exception) {
            self::assertStringContainsString('revoked', $exception->getMessage());
        }

        $lineage = $this->lineage($handle->uuid);
        self::assertInstanceOf(RunLineage::class, $lineage);
        self::assertFalse($lineage->holdsProcess());

        self::assertTrue($this->persister->settleCancelled($handle));
        self::assertNull($this->prepare($this->request($configuration, null, predecessor: $handle->uuid))->pin, 'the next turn goes on without it');
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

        $this->expectException(SkillInstructionWithdrawnException::class);
        $this->expectExceptionMessage('disabled');
        $this->prepare($this->request($configuration, null, predecessor: $predecessor));
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
        return $this->preparer()->prepare($request, $this->admin, $this->begin());
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
    private function finishedProcessRun(LlmConfiguration $configuration, ?SubjectRecord $subject = null): string
    {
        $handle = $this->begin($configuration);
        self::assertTrue($this->persister->recordLineage($handle, new RunLineage(self::CONFIGURATION, $this->pinOf(self::PROCESS), $subject)));
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

    private function preparer(): ProcessRunPreparer
    {
        $factory = $this->composerFactory();

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
