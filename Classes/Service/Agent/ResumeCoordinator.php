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
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\InputSchema;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use RuntimeException;

/**
 * Picks a suspended run back up: an approval decision (ADR-084) or a submitted
 * input (ADR-105).
 *
 * Both follow the same claim protocol, and the order in it is the safety
 * property: probe that a resume handle can be built at all, win the atomic
 * claim so a second caller cannot resume the same run, then re-resolve the
 * event-stream position from a FRESH row — the claim moved it. A position that
 * cannot be resolved after the claim settles the run rather than leaving it
 * RUNNING with nowhere to write.
 *
 * The one deliberate divergence is ADR-105's: a submitted input is validated
 * against the tool's declared schema BEFORE anything is probed or claimed, so a
 * rejection leaves the run WAITING_FOR_INPUT with nothing claimed and no event
 * recorded and the user can simply resubmit. An approval has nothing to
 * validate and claims first.
 *
 * Both refuse a caller that may not act on the run, and both resume tools under
 * the RUN OWNER's identity rather than the approver's or the submitter's
 * (ADR-083).
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
    ) {}

    /**
     * Resume a run waiting on an approval decision (ADR-084).
     *
     * Claims first — an approval has nothing to validate — then continues the
     * tool loop under the run OWNER's identity. Throws rather than returning a
     * failed result when the run is not resumable: the caller distinguishes
     * "wrong state", "not yours", "already resuming" and "corrupt state".
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    public function approve(AiActorContext $actor, string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_APPROVAL || $run->suspendedState === null) {
            throw RunNotAwaitingApprovalException::forRun($runUuid);
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

        // Probe the event-stream position BEFORE the claim: a failure here
        // refuses the resume while the run is still WAITING_FOR_APPROVAL, so
        // the approval can simply be retried (nothing was claimed or executed).
        if ($this->persister->resumeHandle($run) === null) {
            throw RunStateUnavailableException::forRun($runUuid);
        }

        // Atomically claim the run before executing its pending (approval-gated,
        // possibly destructive) tool calls, so two concurrent Approve requests
        // cannot both run them (ADR-084). Fail-closed on a store error too.
        if (!$this->persister->claimResume($run)) {
            throw RunAlreadyResumingException::forRun($runUuid);
        }

        // Re-resolve the position from a FRESH row snapshot AFTER winning the
        // claim: a request that stalled between findRun and the claim may hold
        // a stale position from before another approval's continuation
        // appended events — writing there would duplicate sequences and
        // interleave segments. The claim is won, so a failure now settles the
        // run rather than stranding it RUNNING (fail-closed either way).
        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed !== null ? $this->persister->resumeHandle($claimed) : null;
        if ($handle === null) {
            $this->persister->settleFailed(
                new AgentRunHandle($run->uid, $run->uuid),
                new RuntimeException('The event-stream position could not be determined after the resume claim'),
            );

            throw RunStateUnavailableException::forRun($runUuid);
        }

        // The decision is part of the run's audit stream (best-effort, ADR-101):
        // who approved or denied, before the continuation's own events.
        $this->persister->recordApproval($handle, $decision->approved, $decision->decidedByBeUser);

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
        );
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
        if ($run === null || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_INPUT || $run->suspendedState === null) {
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
        if ($this->persister->resumeHandle($run) === null) {
            throw RunStateUnavailableException::forRun($runUuid);
        }

        if (!$this->persister->claimResumeFromInput($run)) {
            throw RunAlreadyResumingException::forRun($runUuid);
        }

        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed !== null ? $this->persister->resumeHandle($claimed) : null;
        if ($handle === null) {
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
        );
    }
}
