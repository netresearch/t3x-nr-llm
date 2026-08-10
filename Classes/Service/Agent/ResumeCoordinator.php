<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\Exception\ApprovalNotAuditableException;
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\InputSchema;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Picks a suspended run back up: an approval decision (ADR-084) or a submitted
 * input (ADR-105).
 *
 * Both follow the same claim protocol, and the order in it is the safety
 * property: refuse what can be refused without touching the row, probe that a
 * resume handle can be built at all, win the atomic claim so a second caller
 * cannot resume the same run, then re-resolve the event-stream position from a
 * FRESH row — the claim moved it. A position that cannot be resolved after the
 * claim settles the run rather than leaving it RUNNING with nowhere to write.
 * Since ADR-132 the approval path reads the SUSPENDED STATE it executes from
 * that fresh row, not from the pre-claim read: a lost race lets the run suspend
 * again on a different turn, and the pre-claim copy would be the previous one.
 * It still DECODES the pre-claim copy first, purely to reject a row that was
 * already unreadable, because that rejection can be non-destructive and the
 * post-claim one cannot.
 *
 * The one deliberate divergence is ADR-105's: a submitted input is validated
 * against the tool's declared schema BEFORE anything is probed or claimed, so a
 * rejection leaves the run WAITING_FOR_INPUT with nothing claimed and no event
 * recorded and the user can simply resubmit. An approval has nothing to
 * validate and claims first.
 *
 * Both refuse a caller that may not act on the run, and both resume tools under
 * the RUN OWNER's identity rather than the approver's or the submitter's
 * (ADR-083). Because the owner's identity is what executes, the APPROVER is
 * checked against the pending writes separately (ADR-133) — an approver who
 * could not run the call themselves may not have it run for them.
 */
final readonly class ResumeCoordinator
{
    public function __construct(
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurationRepository,
        private ToolLoopServiceInterface $toolLoop,
        private AgentRunExecutor $executor,
        // Validates a submitted input against a tool's declared schema
        // (ADR-105). A null falls back to a fresh stateless validator;
        // submitInput() always validates.
        private ?JsonSchemaValidator $schemaValidator = null,
        // Classifies the pending turn's calls so an unrecorded approval fails
        // closed only where it must (ADR-132). Optional for the positional test
        // wiring, like the validator above; production autowires it. A null
        // resolver classifies EVERY turn as writing — the opposite of
        // AgentRunExecutor's null arm, and deliberately so: there the null means
        // "no write fence at all", here it would mean "waive the audit
        // requirement", and this guard only ever fires when the audit write
        // already failed.
        private ?ToolEffectResolver $toolEffectResolver = null,
        // The ONE digest definition (ADR-132), shared with the inbox view
        // factory so what is rendered and what is verified cannot drift.
        private ?PendingTurnDigest $turnDigest = null,
        // The composite tool gate (ADR-094), asked whether the APPROVER could
        // run the writes they are releasing (ADR-133). Optional for the
        // positional test wiring like the collaborators above; production
        // autowires it, and AgentRuntime hands its own down.
        //
        // A null is the ONE arm in which this gate does not run. Deliberately
        // not "refuse every write": without a gate there is no verdict to fail
        // closed ON, and refusing would make the bare positional construction
        // unable to approve anything at all rather than merely unchecked. The
        // arm is unreachable from the container, where the interface is aliased.
        private ?ToolCallPolicyInterface $toolPolicy = null,
        // Turns the APPROVER's uid into a live backend user for that gate
        // (ADR-083's resolver, used here on the deciding identity rather than
        // the executing one). A null falls back to a fresh default instance,
        // exactly as in AgentRunExecutor — the fallback resolves from the
        // database and is not a weakening.
        private ?ActingBackendUserResolverInterface $actingBackendUserResolver = null,
        // Records a refused approval (ADR-133): who was turned away, on which
        // tool, for which reason. The exception carries the same facts to the
        // surface; the log line is what survives the request.
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Resume a run waiting on an approval decision (ADR-084 / ADR-132).
     *
     * Claims first — an approval has nothing to validate — then continues the
     * tool loop under the run OWNER's identity. Throws rather than returning a
     * failed result when the run is not resumable: the caller distinguishes
     * "wrong state", "not yours", "already resuming", "corrupt state", "you
     * reviewed a different turn", "you may not run that write" and "the decision
     * could not be recorded".
     *
     * The state that is resumed is the one loaded AFTER the claim, never the one
     * read before it. Lose the race and the run does not simply stay put: the
     * winner runs the turn, the loop continues, and the run can suspend AGAIN on
     * a DIFFERENT turn — which is exactly the row our claim then succeeds on.
     * The pre-claim read would be the previous turn, so the digest check, the
     * write classification and the execution all have to use the fresh state or
     * they judge a turn that is no longer pending.
     *
     * The state is nevertheless DECODED twice, and the two decodes answer
     * different questions. The pre-claim one asks "was this row readable when we
     * found it" and refuses without claiming, so a row corrupted outside the
     * extension stays WAITING_FOR_APPROVAL with its blob intact. The post-claim
     * one asks "is the row we actually won readable" and must settle the run on
     * a no, because by then the claim is held. Dropping the first would make the
     * ordinary corrupt-row case terminal and erase the evidence with it;
     * dropping the second would let the race resume an unverified turn.
     *
     * Three fail-closed gates sit between the claim and the execution, and all
     * three RELEASE the run (suspend it back to WAITING_FOR_APPROVAL, which
     * clears the claim and the lease and writes the state back) rather than
     * settling it: the decision was refused, nothing ran, and the operator can
     * decide again.
     *
     * 1. The decision must name the turn it was made on. A null digest and a
     *    mismatching digest both mean "the reviewed turn is not known", so both
     *    are refused. This is the ONLY place the invariant lives — the surfaces
     *    just carry the value through.
     * 2. The APPROVER must be permitted to run every pending call that declares
     *    a write (ADR-133). Execution stays the owner's (ADR-083), which is
     *    precisely why: without this, a non-admin holding the `agent_approve`
     *    grant could release an admin-only write and have it executed on the
     *    owner's authority.
     * 3. An approval whose APPROVAL event could not be stored may not execute
     *    a turn that declares a write. A read-only turn
     *    stays fail-soft (logged, and it continues): the audit gap is real but
     *    nothing changes state, and refusing would strand a harmless run.
     *
     * Gate 2 sits between them on purpose. After gate 1, because it judges the
     * calls of the turn that was actually reviewed and there is no point
     * resolving a backend user for a decision already known to be stale; before
     * gate 3, because a refused approval must not be written into the audit
     * stream as a decision that stood — the same reason gate 1 releases before
     * anything is recorded.
     *
     * A DENIAL passes gate 1 — it is a decision on a turn like any other, and
     * denying a turn the operator never saw is exactly as wrong as approving it
     * — but deliberately NOT gates 2 and 3. Both exist to stop a WRITE from
     * executing (unauthorised or unaudited); a denial executes nothing, so there
     * is nothing to stop. Refusing it would only leave the write-declaring turn
     * pending and approvable while the operator who wanted it gone is turned
     * away — a worse state than a logged, unrecorded "no". The "who denied"
     * record is still lost, which is why the failure is logged rather than
     * silent.
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    public function approve(AiActorContext $actor, string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if (!$run instanceof AgentRun || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_APPROVAL || $run->suspendedState === null) {
            throw RunNotAwaitingApprovalException::forRun($runUuid);
        }

        if (!$actor->mayActOnRun($run, ServiceAccountScope::AGENT_APPROVE)) {
            throw RunAccessDeniedException::forActor($actor, $runUuid);
        }

        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($configuration === null) {
            throw RunConfigurationGoneException::forRun($runUuid);
        }

        // Reject an already-unreadable state BEFORE the claim, and do not carry
        // the result forward — the decode after the claim is the authoritative
        // one. This one exists so the common case (the row was corrupt before
        // anyone clicked) is refused non-destructively: nothing is claimed, the
        // run stays WAITING_FOR_APPROVAL and its suspended_state survives for
        // repair. Without it the first Approve claims the run and the post-claim
        // decode settles it FAILED, and settling clears suspended_state — the
        // very blob a repair would need (compare AgentRunExecutor's rule against
        // flipping a resumable run to FAILED and destroying its state).
        if (!is_array(json_decode($run->suspendedState, true))) {
            throw CorruptSuspendedStateException::forRun($runUuid);
        }

        // Probe the event-stream position BEFORE the claim: a failure here
        // refuses the resume while the run is still WAITING_FOR_APPROVAL, so
        // the approval can simply be retried (nothing was claimed or executed).
        if (!$this->persister->resumeHandle($run) instanceof AgentRunHandle) {
            throw RunStateUnavailableException::forRun($runUuid);
        }

        // Atomically claim the run before executing its pending (approval-gated,
        // possibly destructive) tool calls, so two concurrent Approve requests
        // cannot both run them (ADR-084). Fail-closed on a store error too.
        // The claim carries this segment's identity and lease (ADR-141): the
        // approved calls execute here, so this is the segment the write fence
        // arms on.
        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResume($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            throw RunAlreadyResumingException::forRun($runUuid);
        }

        // Re-resolve the position from a FRESH row snapshot AFTER winning the
        // claim: a request that stalled between findRun and the claim may hold
        // a stale position from before another approval's continuation
        // appended events — writing there would duplicate sequences and
        // interleave segments. The claim is won, so a failure now settles the
        // run rather than stranding it RUNNING (fail-closed either way).
        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed instanceof AgentRun ? $this->persister->resumeHandle($claimed) : null;
        if (!$claimed instanceof AgentRun || !$handle instanceof AgentRunHandle) {
            $this->persister->settleFailed(
                new AgentRunHandle($run->uid, $run->uuid),
                new RuntimeException('The event-stream position could not be determined after the resume claim'),
            );

            throw RunStateUnavailableException::forRun($runUuid);
        }

        // Decode the state the run is ACTUALLY suspended on, from that same
        // fresh row. The pre-claim decode already refused a row that was corrupt
        // when we read it, so what is left here is the race: the state CHANGED
        // between the two reads (a lost race let another approval run the turn
        // and the run suspended again) and the new one is unreadable. Corrupt at
        // this point means the run cannot continue and cannot be released either
        // — an unreadable state cannot be written back — so settle it rather
        // than leave it RUNNING with a won claim and nowhere to go.
        $decoded = $claimed->suspendedState !== null ? json_decode($claimed->suspendedState, true) : null;
        if (!is_array($decoded)) {
            $this->persister->settleFailed(
                $handle,
                new RuntimeException('The suspended run state could not be decoded after the resume claim'),
            );

            throw CorruptSuspendedStateException::forRun($runUuid);
        }

        /** @var array<string, mixed> $decoded */
        $state = SuspendedRunState::fromArray($decoded);

        // Gate 1 — the decision must name THIS turn. hash_equals rather than
        // !== because comparing digests is what it is for, not because the
        // digest is a secret: it is a sha256 of the pending calls and the card
        // renders it, so anyone who can see the turn can compute it. What this
        // catches is STALENESS — a tab left open, or a second operator whose
        // approval already let the run suspend on a different turn — not
        // forgery.
        $current = $this->turnDigest()->forState($state);
        if ($decision->turnDigest === null || !hash_equals($current, $decision->turnDigest)) {
            $this->release($handle, $state, $runUuid);

            throw StaleApprovalTurnException::forRun($runUuid);
        }

        // Gate 2 — the approver must be permitted to run what they release
        // (ADR-133). Only an approval is checked: a denial executes nothing.
        $refusal = $decision->approved ? $this->approverRefusal($actor, $configuration, $state, $runUuid) : null;
        if ($refusal instanceof ApproverNotPermittedException) {
            $this->logger?->warning('Approval refused: the approver may not run the pending write', [
                'run'    => $runUuid,
                'actor'  => $actor->describe(),
                'reason' => $refusal->getMessage(),
            ]);
            $this->release($handle, $state, $runUuid);

            throw $refusal;
        }

        // Gate 3 — the decision is part of the run's audit stream (ADR-101):
        // who approved or denied, before the continuation's own events. An
        // approval that authorises a write and could not be recorded does not
        // execute.
        $recorded = $this->persister->recordApproval($handle, $decision->approved, $decision->decidedByBeUser);
        if (!$recorded && $decision->approved && $this->turnDeclaresWrite($state)) {
            $this->release($handle, $state, $runUuid);

            throw ApprovalNotAuditableException::forRun($runUuid);
        }

        // Tools resume under the RUN OWNER's identity (ADR-083), not whoever is
        // approving: the owner is who the queued work acts for. Reconstructed
        // from the run row's initiating uid — the same fallback the request codec
        // uses — and resolved to a live user, so authorization is identical to
        // the original synchronous/worker execution.
        return $this->executor->executeResume(
            $handle,
            $onStep,
            AiActorContext::backendUser($run->beUser),
            fn(ToolExecutionContext $context, RunTrace $trace): ToolLoopResult => $this->toolLoop->resume(
                $state,
                $decision->approved,
                $configuration,
                $context,
                null,
                $trace,
                $decision->decidedByBeUser,
            ),
            $leaseOwner,
        );
    }

    /**
     * Hand a claimed run back to the operator: the existing RUNNING ->
     * WAITING_FOR_APPROVAL transition, which clears the claim and the lease and
     * writes the (unchanged) state back, so the run is decidable again.
     *
     * A release that itself fails would leave the run RUNNING with no worker —
     * invisible to the inbox and to the stale-run reaper alike — so that case
     * settles it failed instead. Either way nothing was executed.
     */
    private function release(AgentRunHandle $handle, SuspendedRunState $state, string $runUuid): void
    {
        if ($this->persister->suspend($handle, $state)) {
            return;
        }

        $this->persister->settleFailed(
            $handle,
            new RuntimeException(sprintf('Run %s could not be released back to its approval pause after a refused decision', $runUuid !== '' ? $runUuid : 'unknown')),
        );
    }

    /**
     * Whether the pending turn contains at least one call that DECLARES a write
     * (ADR-111 effects).
     *
     * Fail-closed twice over: an unknown tool name resolves to
     * NON_IDEMPOTENT_WRITE inside {@see ToolEffectResolver::effectFor()}, and an
     * entry too corrupt to yield a call at all is counted as a write here —
     * an unclassifiable call is the dangerous case, not the harmless one.
     * {@see ToolCall::tryFromArray()} rather than
     * {@see SuspendedRunState::toolCalls()} for exactly that reason: toolCalls()
     * throws on a corrupt entry, and this guard must return a verdict, not
     * escalate a classification problem into an uncaught error on a claimed run.
     */
    private function turnDeclaresWrite(SuspendedRunState $state): bool
    {
        foreach ($state->pendingCalls as $raw) {
            $call = ToolCall::tryFromArray($raw);
            if (!$call instanceof ToolCall) {
                return true;
            }

            if (($this->toolEffectResolver?->effectFor($call->name) ?? ToolEffect::NON_IDEMPOTENT_WRITE)->isWrite()) {
                return true;
            }
        }

        return false;
    }

    private function turnDigest(): PendingTurnDigest
    {
        return $this->turnDigest ?? new PendingTurnDigest();
    }

    /**
     * Why this approver may not release this turn, or null when they may
     * (ADR-133).
     *
     * Resume executes under the run OWNER's identity (ADR-083) — deliberately,
     * and unchanged. That is exactly what makes the approver's own authority
     * load-bearing: {@see AiActorContext::mayActOnRun()} grants the DECISION on
     * the `agent_approve` grant alone, so without this the grant would let a
     * non-admin release an admin-only write that then runs on the owner's
     * authority. Every pending call that DECLARES a write is therefore put
     * through the same gate the execution would pass, evaluated against the
     * approver's live backend user. Read-only calls are not checked: nothing
     * changes state, and the owner's own gate still governs what runs.
     *
     * Fail-closed in three places:
     *
     * - A SERVICE ACCOUNT may not release a write-declaring turn at all. Its
     *   authority is scopes, not backend permissions:
     *   {@see AiActorContext::hasGrant()} is false for it by construction and it
     *   has no backend-user uid, so {@see ToolCallPolicyInterface::decide()} would
     *   see `$user === null` and check only enabled/group/zone — the admin axis
     *   bites solely on `requiresAdmin()`, so a write tool without that flag
     *   would pass a gate that is effectively absent while a human is checked
     *   properly. Refusing is the only variant that stays fail-closed without
     *   inventing a second authorisation axis for service accounts.
     * - A human whose uid no longer resolves to an enabled backend user is
     *   refused for the same reason: there is no live permission surface to
     *   check, and "no user" is not "permitted".
     * - A pending entry too corrupt to yield a call is refused, mirroring
     *   {@see self::turnDeclaresWrite()} — an unclassifiable call is the
     *   dangerous case, not the harmless one.
     *
     * Observe mode is honoured as-is: the gate reports `allowed` there, and this
     * check must not be stricter than the execution it mirrors.
     */
    private function approverRefusal(AiActorContext $actor, LlmConfiguration $configuration, SuspendedRunState $state, string $runUuid): ?ApproverNotPermittedException
    {
        if (!$this->toolPolicy instanceof ToolCallPolicyInterface) {
            return null;
        }

        // A service account has no backend user by construction; resolving would
        // return null anyway, but not asking says why.
        $approver = $actor->isServiceAccount()
            ? null
            : ($this->actingBackendUserResolver ?? new ActingBackendUserResolver())->resolve($actor);

        foreach ($state->pendingCalls as $raw) {
            $call = ToolCall::tryFromArray($raw);
            if (!$call instanceof ToolCall) {
                return ApproverNotPermittedException::forUnreadableCall($runUuid, $actor);
            }

            if (!($this->toolEffectResolver?->effectFor($call->name) ?? ToolEffect::NON_IDEMPOTENT_WRITE)->isWrite()) {
                continue;
            }

            if (!$approver instanceof BackendUserAuthentication) {
                return ApproverNotPermittedException::forApproverWithoutPermissions($runUuid, $actor, $call->name);
            }

            $verdict = $this->toolPolicy->decide($call->name, $configuration, $approver);
            if (!$verdict->allowed) {
                return ApproverNotPermittedException::forDeniedTool($runUuid, $actor, $call->name, $verdict->reason);
            }
        }

        return null;
    }

    /**
     * Resume a run waiting on a submitted input (ADR-105).
     *
     * Validates the submission against the tool's declared schema BEFORE
     * probing or claiming, so a rejection leaves the run WAITING_FOR_INPUT with
     * nothing claimed and the user can resubmit. From there the flow is
     * {@see self::approve()}'s.
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    public function submitInput(AiActorContext $actor, string $runUuid, InputSubmission $submission, ?Closure $onStep = null): AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if (!$run instanceof AgentRun || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_INPUT || $run->suspendedState === null) {
            throw RunNotAwaitingInputException::forRun($runUuid);
        }

        if (!$actor->mayActOnRun($run, ServiceAccountScope::AGENT_APPROVE)) {
            throw RunAccessDeniedException::forActor($actor, $runUuid);
        }

        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($configuration === null) {
            throw RunConfigurationGoneException::forRun($runUuid);
        }

        $decoded = json_decode($run->suspendedState, true);
        if (!is_array($decoded)) {
            throw CorruptSuspendedStateException::forRun($runUuid);
        }

        /** @var array<string, mixed> $decoded */
        $state = SuspendedRunState::fromArray($decoded);

        // Well-formedness gate (ADR-105 M2): an input suspension with no target
        // tool or a degenerate schema is corruption, never "accept anything".
        // validate($data, []) returns true, so this path must be unreachable
        // before the validation below — a corrupt row is a 500, not resumable.
        if ($state->inputToolName === null || $state->inputToolName === '' || !InputSchema::isUsable($state->inputSchema)) {
            throw CorruptSuspendedStateException::forRun($runUuid);
        }

        // DIVERGENCE from approve() (ADR-105): validate the submission BEFORE
        // probing or claiming. A rejection leaves the run WAITING_FOR_INPUT with
        // nothing claimed and no event recorded, so the user can simply resubmit.
        $validator = $this->schemaValidator ?? new JsonSchemaValidator();
        if (!$validator->validate($submission->data, $state->inputSchema)) {
            throw InvalidInputSubmissionException::forRun($runUuid);
        }

        // From here the flow is identical to approve(): probe-before-claim,
        // atomic claim, re-resolve the stream position from a fresh row.
        if (!$this->persister->resumeHandle($run) instanceof AgentRunHandle) {
            throw RunStateUnavailableException::forRun($runUuid);
        }

        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResumeFromInput($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            throw RunAlreadyResumingException::forRun($runUuid);
        }

        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed instanceof AgentRun ? $this->persister->resumeHandle($claimed) : null;
        if (!$handle instanceof AgentRunHandle) {
            $this->persister->settleFailed(
                new AgentRunHandle($run->uid, $run->uuid),
                new RuntimeException('The event-stream position could not be determined after the resume claim'),
            );

            throw RunStateUnavailableException::forRun($runUuid);
        }

        // The submission is part of the run's audit stream (best-effort): who
        // submitted, before the continuation's own events — never the values.
        $this->persister->recordInput($handle, $submission->submittedByBeUser);

        // Tools resume under the RUN OWNER's identity (ADR-083), not the
        // submitter — same rule as approve().
        return $this->executor->executeResume(
            $handle,
            $onStep,
            AiActorContext::backendUser($run->beUser),
            fn(ToolExecutionContext $context, RunTrace $trace): ToolLoopResult => $this->toolLoop->resumeWithInput(
                $state,
                $submission->data,
                $configuration,
                $context,
                null,
                $trace,
                $submission->submittedByBeUser,
            ),
            $leaseOwner,
        );
    }
}
