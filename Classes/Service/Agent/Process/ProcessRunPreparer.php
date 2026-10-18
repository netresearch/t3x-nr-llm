<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Closure;
use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessVerdictUnavailableException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLineageUnreadableException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Psr\Log\LoggerInterface;
use Throwable;
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
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws ProcessInvocationRefusedException
     * @throws ProcessRunNeedsSecondApproverException a continued process on a configuration that turned on four-eyes
     * @throws ProcessVerdictUnavailableException     a read the start rests on failed; the run is tried again
     */
    public function prepare(AgentRunRequest $request, ?BackendUserAuthentication $actingUser, ?AgentRunHandle $handle): PreparedProcess
    {
        if (!$request->invocation instanceof SkillInvocation && $request->predecessorRunUuid === '') {
            return new PreparedProcess();
        }

        $runUuid       = $handle->uuid ?? '';
        $configuration = $request->configuration;
        // The checks read the actor as the acting user is now, not as the
        // request froze it: a queued run is checked at pickup, and an
        // administrator demoted in between is no longer one.
        $actor = $this->liveActor($request->actor, $actingUser);
        if (!$this->configurations->actorMayUse($configuration, $actor)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED);
        }

        [$derived, $dropped, $readmit] = $request->predecessorRunUuid !== ''
            ? $this->derive($request, $actor, $runUuid)
            : [null, [], false];

        $pin      = $derived?->processPin;
        $subject  = $derived?->subject;
        $newStart = $readmit;
        $invoked  = $request->invocation;
        if ($invoked instanceof SkillInvocation && $invoked->skillUid !== $pin?->skillUid) {
            if ($pin instanceof SkillPin) {
                throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PROCESS_HELD);
            }

            $pin      = $this->startPin($configuration, $invoked->skillUid, $runUuid);
            $subject  = $invoked->subject;
            $newStart = true;
        }

        if ($pin instanceof SkillPin) {
            $this->assertMayRunProcess($request, $actor, $runUuid, $derived instanceof RunLineage);
            if ($subject instanceof SubjectRecord && !$this->readApprovals($pin->skillUid, fn(): bool => $this->subjectAccess->isReadable($actingUser, $subject))) {
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
            new RunLineage(
                (int)$configuration->getUid(),
                $pin,
                $subject,
                processSkill: $this->attachedSkill($configuration, $pin->skillUid)?->getIdentifier() ?? '',
                originRunUuid: $derived instanceof RunLineage
                    ? ($derived->originRunUuid !== '' ? $derived->originRunUuid : $request->predecessorRunUuid)
                    : $runUuid,
            ),
            $runUuid,
        );

        return new PreparedProcess($pin, $subject, $dropped, $newStart);
    }

    /**
     * The subject record a resumed run's process is about, when the acting
     * user may still read it (ADR-228); null otherwise, so a highlight on
     * resume is checked against the same rule as the start.
     */
    public function readableSubject(?BackendUserAuthentication $actingUser, ?SubjectRecord $subject): ?SubjectRecord
    {
        return $subject instanceof SubjectRecord && $this->subjectAccess->isReadable($actingUser, $subject) ? $subject : null;
    }

    /**
     * The process the predecessor holds, if the continuation carries it on,
     * the notices for a pin it drops, and whether the predecessor's process
     * section was never admitted against the window, so this run admits it.
     *
     * @return array{0: RunLineage|null, 1: list<DroppedSource>, 2: bool}
     */
    private function derive(AgentRunRequest $request, AiActorContext $actor, string $runUuid): array
    {
        // Not mayActOnRun(): what that admits depends on the scope, and a
        // continuation derives another run's instructions and subject record,
        // which is more than reading or deciding it (ADR-214 item 6). A run
        // that does not exist and one the actor may not continue are refused
        // alike, so the refusal does not tell another user's run uuids apart.
        $predecessor = $this->persister->findRun($request->predecessorRunUuid);
        if (!$predecessor instanceof AgentRun
            || (!$actor->isInitiatorOf($predecessor) && (!$actor->isAdmin || $actor->isServiceAccount()))) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_MISSING);
        }

        // A process the predecessor released, or whose start was released
        // since — the end of the process, from any of its turns — is not
        // carried on.
        try {
            $lineage = $this->persister->heldProcessOf($predecessor);
        } catch (RunLineageUnreadableException) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::LINEAGE_UNREADABLE);
        }

        if (!$lineage instanceof RunLineage || !$lineage->processPin instanceof SkillPin) {
            return [null, [], false];
        }

        // nr_llm only checks; getting the predecessor to a terminal state is
        // the chat's job, through the guarded cancel (ADR-214 item 9).
        if ($predecessor->statusEnum()?->isTerminal() !== true) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED);
        }

        if ($lineage->configurationUid !== (int)$request->configuration->getUid()) {
            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::CONFIGURATION_CHANGED)], false];
        }

        if (!$this->attachedSkill($request->configuration, $lineage->processPin->skillUid) instanceof Skill) {
            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::DETACHED)], false];
        }

        // A predecessor that never sent a provider call never got its
        // sections past the window — refused there, a dry run, cancelled or
        // failed before the send — so the conversation does not hold the
        // process yet: this run admits it (ADR-214 item 7).
        return [$lineage, [], !$this->persister->sentProviderCall($predecessor)];
    }

    /**
     * The request's actor as the acting backend user is now: its admin flag
     * and groups. Fails closed — no admin flag, no groups — when there is no
     * acting user or it is another user than the actor. A service account
     * has no backend user and keeps its declared scopes.
     */
    private function liveActor(AiActorContext $actor, ?BackendUserAuthentication $user): AiActorContext
    {
        if ($actor->isServiceAccount()) {
            return $actor;
        }

        if (!$user instanceof BackendUserAuthentication || $user->getUserId() !== $actor->backendUserUid) {
            return AiActorContext::backendUser($actor->backendUserUid, false, [], $actor->grants);
        }

        return AiActorContext::backendUser(
            $actor->backendUserUid,
            $user->isAdmin(),
            array_values(array_map(static fn(mixed $g): int => is_numeric($g) ? (int)$g : 0, $user->userGroupsUID)),
            $actor->grants,
        );
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

        // The trust floor (ADR-061) the catalogue applies too: a skill the
        // composer does not admit is neither listed nor started.
        $admitted = $this->readApprovals($skillUid, fn(): array => $this->composer->admittedSkills([$skill], []));
        if (!in_array($skill, $admitted, true)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SKILL_NOT_ADMITTED);
        }

        $version = $this->readApprovals($skillUid, fn(): ?SkillApproval => $this->composer->approvedVersion($skill));
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
    private function assertMayRunProcess(AgentRunRequest $request, AiActorContext $actor, string $runUuid, bool $continued): void
    {
        if ($actor->isServiceAccount()) {
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
     * longer holds stops the run with a refusal naming why, after the run
     * recorded its lineage without the pin: the next turn then goes on
     * without the process instead of failing on it for ever.
     */
    private function assertDerivedPinHolds(SkillPin $pin, LlmConfiguration $configuration, ?AgentRunHandle $handle, ?SubjectRecord $subject): void
    {
        $reason = $this->readApprovals($pin->skillUid, fn(): ?string => $this->pinCheck->failure($pin));
        if ($reason === null && $this->processPins->processSnapshot($pin) instanceof SkillApproval) {
            return;
        }

        $this->record($handle, new RunLineage((int)$configuration->getUid(), null, $subject), $handle->uuid ?? '');

        throw ProcessInvocationRefusedException::forRun(
            $handle->uuid ?? '',
            ProcessInvocationRefusal::PIN_WITHDRAWN,
            $reason ?? 'its approval no longer marks a process',
        );
    }

    /**
     * A read of what a process rests on: its approvals, the trust of their
     * source, and the subject record. A store that cannot answer is not an
     * answer: the read throws the retryable refusal, so the run is tried
     * again rather than stopped as if the approval were gone or the record
     * unreadable (ADR-228). The cause is logged, since the refusal does not
     * carry it.
     *
     * @template T
     *
     * @param Closure(): T $read
     *
     * @throws ProcessVerdictUnavailableException
     *
     * @return T
     */
    private function readApprovals(int $skillUid, Closure $read): mixed
    {
        try {
            return $read();
        } catch (ProcessVerdictUnavailableException $unavailable) {
            throw $unavailable;
        } catch (Throwable $exception) {
            $this->logger?->warning('A read a process start rests on failed; the run is tried again.', ['skill' => $skillUid, 'exception' => $exception]);

            throw ProcessVerdictUnavailableException::forPin($skillUid);
        }
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
