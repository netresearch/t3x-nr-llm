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
 * rules: only its initiator or an administrator may continue it, and a
 * predecessor that is gone — purged, never there, or not the actor's to
 * continue — drops the process with a notice; a predecessor holding a
 * process must be terminal; an unreadable lineage refuses; and a pin that
 * fails stops the run, which records its lineage without the pin so the
 * next turn goes on without the process. A
 * pin whose skill was detached, or a turn on another configuration, drops the
 * pin with a notice and goes on. An invocation of the skill the derived pin
 * already names is the same process, not a new start.
 *
 * What it returns is recorded as the run's lineage before the run starts;
 * a run that cannot record it is refused, because its next turn would lose
 * the process.
 *
 * A refused continuation does not lose the process either: it records the
 * lineage it would have carried on, or, when it could not read one, a
 * lineage that names the run it continued. A continuation of the refused run
 * follows that name back to the last run that ran, and is held to that run's
 * rules: a process it carries on, a predecessor that must be finished. Only
 * a withdrawn pin, an explicit end and a predecessor that is gone let the
 * process go, the last with a notice.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ProcessRunPreparer
{
    /**
     * How many refused turns in a row a continuation follows back before it
     * lets the process go with a refused_too_often notice.
     */
    private const MAX_CARRIED = 32;

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
        $carry         = null;
        $nothingHeld   = false;

        try {
            return $this->prepareChecked($request, $actingUser, $handle, $runUuid, $configuration, $carry, $nothingHeld);
        } catch (ProcessInvocationRefusedException|ProcessRunNeedsSecondApproverException|ProcessVerdictUnavailableException $refused) {
            $this->carryOn($request, $handle, $refused, $carry, $nothingHeld);

            throw $refused;
        }
    }

    /**
     * The checks of {@see self::prepare()}. $carry receives the lineage the
     * run would carry on as soon as it is known, and $nothingHeld whether the
     * predecessor held no process at all, so a refusal after that point
     * records what it has.
     *
     * @throws ProcessInvocationRefusedException
     * @throws ProcessRunNeedsSecondApproverException
     * @throws ProcessVerdictUnavailableException
     */
    private function prepareChecked(
        AgentRunRequest $request,
        ?BackendUserAuthentication $actingUser,
        ?AgentRunHandle $handle,
        string $runUuid,
        LlmConfiguration $configuration,
        ?RunLineage &$carry,
        bool &$nothingHeld,
    ): PreparedProcess {
        // The checks read the actor as the acting user is now, not as the
        // request froze it: a queued run is checked at pickup, and an
        // administrator demoted in between is no longer one.
        $actor = $this->liveActor($request->actor, $actingUser);
        if (!$this->configurations->actorMayUse($configuration, $actor)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED);
        }

        [$derived, $dropped, $readmit, $tipUuid] = $request->predecessorRunUuid !== ''
            ? $this->derive($request, $actor, $runUuid, $carry)
            : [null, [], false, ''];
        $nothingHeld = $request->predecessorRunUuid !== '' && !$derived instanceof RunLineage && $dropped === [];

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
            if ($subject instanceof SubjectRecord && !$this->readApprovals($pin->skillUid, fn (): bool => $this->subjectAccess->isReadable($actingUser, $subject))) {
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
                (int) $configuration->getUid(),
                $pin,
                $subject,
                processSkill: $this->attachedSkill($configuration, $pin->skillUid)?->getIdentifier() ?? '',
                originRunUuid: $derived instanceof RunLineage
                    ? $this->lineageOrigin($derived, $tipUuid)
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
     * The predecessor is the last run that ran: a refused turn the request
     * names is followed back through the runs it was carried from.
     *
     * @param RunLineage|null $carry receives the lineage a refusal of this run records
     *
     * @return array{0: RunLineage|null, 1: list<DroppedSource>, 2: bool, 3: string}
     */
    private function derive(AgentRunRequest $request, AiActorContext $actor, string $runUuid, ?RunLineage &$carry): array
    {
        $predecessor = $this->continuable($request->predecessorRunUuid, $actor);
        if (!$predecessor instanceof AgentRun) {
            return [null, [$this->gone()], false, ''];
        }

        for ($hops = 0;; ++$hops) {
            $own   = $this->lineageOrRefuse($predecessor, $runUuid);
            $ended = $own?->processPin instanceof SkillPin && $own->released;
            if (!$own instanceof RunLineage || $own->carriedFromRunUuid === '' || $ended) {
                break;
            }

            // A refused run that is not finished — one requeued after a store
            // error — still runs the process itself (ADR-214 item 9).
            if ($predecessor->statusEnum()?->isTerminal() !== true) {
                throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED);
            }

            // A conversation refused this many turns in a row lets the process
            // go with a notice rather than refusing every later turn.
            if ($hops >= self::MAX_CARRIED) {
                return [null, [new DroppedSource('process', 0, DroppedSourceReason::REFUSED_TOO_OFTEN)], false, ''];
            }

            $predecessor = $this->continuable($own->carriedFromRunUuid, $actor);
            if (!$predecessor instanceof AgentRun) {
                return [null, [$this->gone()], false, ''];
            }
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
            return [null, [], false, $predecessor->uuid];
        }

        $carry = new RunLineage(
            $lineage->configurationUid,
            $lineage->processPin,
            $lineage->subject,
            processSkill: $lineage->processSkill,
            originRunUuid: $lineage->originRunUuid !== '' ? $lineage->originRunUuid : $predecessor->uuid,
            carriedFromRunUuid: $predecessor->uuid,
        );

        // nr_llm only checks; getting the predecessor to a terminal state is
        // the chat's job, through the guarded cancel (ADR-214 item 9).
        if ($predecessor->statusEnum()?->isTerminal() !== true) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED);
        }

        if ($lineage->configurationUid !== (int) $request->configuration->getUid()) {
            // A refusal of this run names the predecessor instead, so its next
            // turn reports the drop again rather than holding the process.
            $carry = null;

            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::CONFIGURATION_CHANGED)], false, $predecessor->uuid];
        }

        if (!$this->attachedSkill($request->configuration, $lineage->processPin->skillUid) instanceof Skill) {
            $carry = null;

            return [null, [new DroppedSource('process', $lineage->processPin->skillUid, DroppedSourceReason::DETACHED)], false, $predecessor->uuid];
        }

        // A predecessor that never sent a provider call never got its
        // sections past the window — refused there, a dry run, cancelled or
        // failed before the send — so the conversation does not hold the
        // process yet: this run admits it (ADR-214 item 7).
        return [$lineage, [], !$this->persister->sentProviderCall($predecessor), $predecessor->uuid];
    }

    /**
     * A run this actor may continue, or null. Not mayActOnRun(): what that
     * admits depends on the scope, and a continuation derives another run's
     * instructions and subject record, which is more than reading or
     * deciding it (ADR-214 item 6). A run that does not exist — purged by
     * retention or never there — and one the actor may not continue answer
     * alike, so the answer does not tell another user's run uuids apart.
     */
    private function continuable(string $uuid, AiActorContext $actor): ?AgentRun
    {
        $run = $this->persister->findRun($uuid);
        if (!$run instanceof AgentRun
            || (!$actor->isInitiatorOf($run) && (!$actor->isAdmin || $actor->isServiceAccount()))) {
            return null;
        }

        return $run;
    }

    /**
     * The notice of a continuation whose predecessor is gone or not its own
     * (ADR-228): the turn goes on without a process, and the chat can say so.
     */
    private function gone(): DroppedSource
    {
        return new DroppedSource('process', 0, DroppedSourceReason::PREDECESSOR_GONE);
    }

    /**
     * @throws ProcessInvocationRefusedException
     */
    private function lineageOrRefuse(AgentRun $run, string $runUuid): ?RunLineage
    {
        try {
            return $this->persister->lineageOf($run);
        } catch (RunLineageUnreadableException) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::LINEAGE_UNREADABLE);
        }
    }

    /**
     * What a refused continuation records, so that its next turn neither
     * loses the process nor escapes the checks (ADR-228): the lineage it
     * would have carried on, or, when it could not read one, a lineage naming
     * the run it continued. Nothing for a run that continues no process, for
     * a withdrawn pin, which recorded its lineage without the process on
     * purpose, and for a lineage the store refused. Best effort: a carry that
     * cannot be stored leaves the refused run without a lineage, as before.
     */
    private function carryOn(AgentRunRequest $request, ?AgentRunHandle $handle, Throwable $refused, ?RunLineage $carry, bool $nothingHeld): void
    {
        if (!$handle instanceof AgentRunHandle || $request->predecessorRunUuid === '' || $nothingHeld) {
            return;
        }

        if ($refused instanceof ProcessInvocationRefusedException
            && in_array($refused->reason, [ProcessInvocationRefusal::PIN_WITHDRAWN, ProcessInvocationRefusal::LINEAGE_NOT_STORED], true)) {
            return;
        }

        $lineage = $carry ?? new RunLineage((int) $request->configuration->getUid(), carriedFromRunUuid: $request->predecessorRunUuid);
        if (!$this->persister->recordLineage($handle, $lineage)) {
            $this->logger?->warning('A refused continuation could not record the process it carries; its next turn may go on without it.', ['run' => $handle->uuid]);
        }
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
            array_values(array_map(static fn (mixed $g): int => is_numeric($g) ? (int) $g : 0, $user->userGroupsUID)),
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
        $admitted = $this->readApprovals($skillUid, fn (): array => $this->composer->admittedSkills([$skill], []));
        if (!in_array($skill, $admitted, true)) {
            throw ProcessInvocationRefusedException::forRun($runUuid, ProcessInvocationRefusal::SKILL_NOT_ADMITTED);
        }

        $version = $this->readApprovals($skillUid, fn (): ?SkillApproval => $this->composer->approvedVersion($skill));
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
        $reason = $this->readApprovals($pin->skillUid, fn (): ?string => $this->pinCheck->failure($pin));
        if ($reason === null && $this->processPins->processSnapshot($pin) instanceof SkillApproval) {
            return;
        }

        $this->record($handle, new RunLineage((int) $configuration->getUid(), null, $subject), $handle->uuid ?? '');

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
     * @return T
     *
     * @throws ProcessVerdictUnavailableException
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

    /**
     * The skill attached to the configuration in either load mode: a process
     * skill is attached 'on_demand', and an invocation and a pin reach either
     * mode (ADR-214 items 4 and 6).
     */
    private function attachedSkill(LlmConfiguration $configuration, int $skillUid): ?Skill
    {
        foreach ($configuration->getAttachedSkills() as $skill) {
            if ($skill->getUid() === $skillUid) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * The origin a derived run records: the lineage's own origin, or the tip
     * run when the lineage names none.
     */
    private function lineageOrigin(RunLineage $derived, string $tipUuid): string
    {
        return $derived->originRunUuid !== '' ? $derived->originRunUuid : $tipUuid;
    }
}
