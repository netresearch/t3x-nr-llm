<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLineageUnreadableException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The start checks of a run that invokes or continues a process (ADR-214
 * items 6 and 10, ADR-228), run once the run has a row and an acting user:
 * at an interactive start and again at worker pickup.
 *
 * A new invocation needs: the actor may use the configuration, the skill is
 * attached to it and enabled, its current version is an approved
 * instruction marked a process, no other process is held, the configuration
 * does not set `require_second_approver`, the actor is not a service account,
 * and the acting user may read the subject record.
 *
 * A continuation names its predecessor run only, and the process, its pin
 * and its subject record are derived from that run's lineage under four
 * rules: only its initiator or an administrator may continue it; a
 * predecessor holding a process must be terminal; a missing or unreadable
 * predecessor refuses; and a pin that fails stops the run, which records its
 * lineage without the pin so the next turn goes on without the process. A
 * pin whose skill was detached, or a turn on another configuration, drops the
 * pin with a notice and goes on. An invocation of the skill the derived pin
 * already names is the same process, not a new start.
 *
 * What it returns is recorded as the run's lineage before the run starts;
 * a run that cannot record it is refused, because its next turn would lose
 * the process.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ProcessRunPreparer
{
    public function __construct(
        private AgentRunPersister $persister,
        private ConfigurationResolver $configurations,
        private SkillComposer $composer,
        private SkillPinCheck $pinCheck,
        private ProcessPinProbe $processPins,
        private SubjectRecordAccess $subjectAccess,
    ) {}

    /**
     * @throws ProcessInvocationRefusedException
     * @throws ProcessRunNeedsSecondApproverException a continued process on a configuration that turned on four-eyes
     * @throws SkillInstructionWithdrawnException     a continued process whose pin no longer holds
     * @throws RunLineageUnreadableException
     */
    public function prepare(AgentRunRequest $request, ?BackendUserAuthentication $actingUser, ?AgentRunHandle $handle): PreparedProcess
    {
        if (!$request->invocation instanceof SkillInvocation && $request->predecessorRunUuid === '') {
            return new PreparedProcess();
        }

        $runUuid       = $handle->uuid ?? '';
        $configuration = $request->configuration;
        if (!$this->configurations->actorMayUse($configuration, $request->actor)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED);
        }

        [$derived, $dropped] = $request->predecessorRunUuid !== ''
            ? $this->derive($request, $runUuid)
            : [null, []];

        $pin      = $derived?->processPin;
        $subject  = $derived?->subject;
        $newStart = false;
        $invoked = $request->invocation;
        if ($invoked instanceof SkillInvocation && $invoked->skillUid !== $pin?->skillUid) {
            if ($pin instanceof SkillPin) {
                throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PROCESS_HELD);
            }

            $pin      = $this->startPin($configuration, $invoked->skillUid, $runUuid);
            $subject  = $invoked->subject;
            $newStart = true;
        }

        if ($pin instanceof SkillPin) {
            $this->assertMayRunProcess($request, $runUuid, $derived instanceof RunLineage);
            if ($subject instanceof SubjectRecord && !$this->subjectAccess->isReadable($actingUser, $subject)) {
                throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SUBJECT_NOT_READABLE);
            }

            if ($derived instanceof RunLineage && $pin === $derived->processPin) {
                $this->assertDerivedPinHolds($pin, $configuration, $handle, $subject);
            }
        }

        if (!$pin instanceof SkillPin) {
            return new PreparedProcess(dropped: $dropped);
        }

        $this->record(
            $handle,
            new RunLineage((int)$configuration->getUid(), $pin, $subject, processSkill: $this->attachedSkill($configuration, $pin->skillUid)?->getIdentifier() ?? ''),
            $runUuid,
        );

        return new PreparedProcess($pin, $subject, $dropped, $newStart);
    }

    /**
     * The process the predecessor holds, if the continuation carries it on,
     * and the notices for a pin it drops.
     *
     * @return array{0: RunLineage|null, 1: list<DroppedSource>}
     */
    private function derive(AgentRunRequest $request, string $runUuid): array
    {
        $predecessor = $this->persister->findRun($request->predecessorRunUuid);
        if (!$predecessor instanceof AgentRun) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_MISSING);
        }

        // Not mayActOnRun(): what that admits depends on the scope, and a
        // continuation derives another run's instructions and subject record,
        // which is more than reading or deciding it (ADR-214 item 6).
        $actor = $request->actor;
        if (!$actor->isInitiatorOf($predecessor) && !($actor->isAdmin && !$actor->isServiceAccount())) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_NOT_PERMITTED);
        }

        $lineage = $this->persister->lineageOf($predecessor);
        if (!$lineage instanceof RunLineage || !$lineage->holdsProcess() || !$lineage->processPin instanceof SkillPin) {
            return [null, []];
        }

        // nr_llm only checks; getting the predecessor to a terminal state is
        // the chat's job, through the guarded cancel (ADR-214 item 9).
        if ($predecessor->statusEnum()?->isTerminal() !== true) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED);
        }

        if ($lineage->configurationUid !== (int)$request->configuration->getUid()) {
            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::CONFIGURATION_CHANGED)]];
        }

        if (!$this->attachedSkill($request->configuration, $lineage->processPin->skillUid) instanceof Skill) {
            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::DETACHED)]];
        }

        return [$lineage, []];
    }

    /**
     * The pin a new invocation starts: the approved version of the skill's
     * current digest, which must be an instruction marked a process.
     */
    private function startPin(LlmConfiguration $configuration, int $skillUid, string $runUuid): SkillPin
    {
        $skill = $this->attachedSkill($configuration, $skillUid);
        if (!$skill instanceof Skill) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SKILL_NOT_ATTACHED);
        }

        if (!$skill->isEnabled() || $skill->isOrphaned()) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SKILL_DISABLED);
        }

        $version = $this->composer->approvedVersion($skill);
        if (!$version instanceof SkillApproval) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::NOT_AN_INSTRUCTION);
        }

        if (!$version->process) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::NOT_A_PROCESS);
        }

        return new SkillPin($version->skillUid, $version->sourceUid, $version->versionDigest);
    }

    /**
     * The two guards of ADR-214 item 6 that make a process decidable at all:
     * an initiator who can decide it, and no four-eyes setting under which
     * nobody could apply a proposal. A continued process on a configuration
     * that turned four-eyes on since stops with the stop's own message.
     */
    private function assertMayRunProcess(AgentRunRequest $request, string $runUuid, bool $continued): void
    {
        if ($request->actor->isServiceAccount()) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SERVICE_ACCOUNT);
        }

        if (!$request->configuration->requiresSecondApprover()) {
            return;
        }

        if ($continued) {
            throw ProcessRunNeedsSecondApproverException::forRun($runUuid, $request->configuration->getIdentifier());
        }

        throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SECOND_APPROVER_REQUIRED);
    }

    /**
     * The pin rules at every continuation (ADR-214 item 6). A pin that no
     * longer holds stops the run, which first records its lineage without the
     * pin: the next turn then goes on without the process instead of failing
     * on it for ever.
     */
    private function assertDerivedPinHolds(SkillPin $pin, LlmConfiguration $configuration, ?AgentRunHandle $handle, ?SubjectRecord $subject): void
    {
        $reason = $this->pinCheck->failure($pin);
        if ($reason === null && $this->processPins->processSnapshot($pin) instanceof SkillApproval) {
            return;
        }

        $this->record($handle, new RunLineage((int)$configuration->getUid(), null, $subject), $handle->uuid ?? '');

        // The pin check names the skill as the approver saw it.
        $this->pinCheck->assertHeld([$pin]);

        throw new SkillInstructionWithdrawnException($pin, 'unknown', 'its approval no longer marks a process');
    }

    private function record(?AgentRunHandle $handle, RunLineage $lineage, string $runUuid): void
    {
        if (!$handle instanceof AgentRunHandle || !$this->persister->recordLineage($handle, $lineage)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::LINEAGE_NOT_STORED);
        }
    }

    private function attachedSkill(LlmConfiguration $configuration, int $skillUid): ?Skill
    {
        foreach (SkillInjectionService::toList($configuration->getSkills()) as $skill) {
            if ($skill->getUid() === $skillUid) {
                return $skill;
            }
        }

        return null;
    }
}
