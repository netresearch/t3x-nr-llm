<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The one place the agent-run lifecycle lives (ADR-101).
 *
 * Extracted from ToolPlaygroundController, which carried this orchestration —
 * begin, trace, persist, the suspend/guardrail/failure/completion ladder, the
 * resume claim — copied three times (batch, resume, stream). Here it exists
 * once; the playground is a UI adapter mapping {@see AgentRunResult} to its
 * response shapes, and any other consumer (CLI, scheduler, review queue) gets
 * the identical fail-closed semantics.
 *
 * Catch order is a hard guarantee (ADR-084): {@see ToolApprovalRequiredException}
 * is control flow, not failure, and MUST be caught before the guardrail pair
 * and before the generic Throwable.
 */
final readonly class AgentRuntime implements AgentRuntimeInterface
{
    /**
     * Server-side ceiling on the per-run round count — a cost-safety invariant:
     * no consumer request may drive an unbounded, cost-accruing loop. Clamps
     * only an explicit {@see AgentRunRequest::$maxIterations}; null passes
     * through so the loop's own (lower) default applies.
     */
    public const MAX_ITERATIONS = 20;

    public function __construct(
        private ToolLoopServiceInterface $toolLoop,
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurationRepository,
        private ?LoggerInterface $logger = null,
    ) {}

    public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult
    {
        $maxIterations = $request->maxIterations !== null
            ? min($request->maxIterations, self::MAX_ITERATIONS)
            : null;

        $handle = $this->persister->begin($request->configuration, $request->beUserUid);
        $trace  = $this->trace($handle, $onStep, $request->captureRaw);

        return $this->execute(
            $handle,
            $trace,
            fn(): ToolLoopResult => $this->toolLoop->runLoop(
                $request->messages,
                $request->configuration,
                $request->allowedToolNames,
                $request->options,
                $maxIterations,
                $trace,
                $request->augmentation,
            ),
        );
    }

    public function approve(string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_APPROVAL || $run->suspendedState === null) {
            throw RunNotAwaitingApprovalException::forRun($runUuid);
        }

        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($configuration === null) {
            throw RunConfigurationGoneException::forRun($runUuid);
        }

        $decoded = json_decode($run->suspendedState, true);
        if (!is_array($decoded)) {
            throw CorruptSuspendedStateException::forRun($runUuid);
        }
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

        $trace = $this->trace($handle, $onStep, false);

        return $this->execute(
            $handle,
            $trace,
            fn(): ToolLoopResult => $this->toolLoop->resume(
                $state,
                $decision->approved,
                $configuration,
                null,
                $trace,
                $decision->decidedByBeUser,
            ),
        );
    }

    public function cancel(string $runUuid): bool
    {
        return $this->persister->cancel($runUuid);
    }

    public function events(string $runUuid, int $afterSequence = -1): array
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null) {
            return [];
        }

        return array_values(array_filter(
            $this->persister->findEvents($run->uid),
            static fn($event): bool => $event->sequence > $afterSequence,
        ));
    }

    public function status(string $runUuid): ?AgentRun
    {
        // The raw suspended transcript bypasses the privacy filter (it must —
        // resume needs it verbatim); the status surface must not expose it.
        return $this->persister->findRun($runUuid)?->withoutSuspendedState();
    }

    /**
     * The trace every segment runs under: each recorded step reaches the live
     * observer FIRST (preserving the streaming path's emit-before-persist
     * order — a step is shown even when its persist fails), then the persisted
     * event stream.
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    private function trace(?AgentRunHandle $handle, ?Closure $onStep, bool $captureRaw): RunTrace
    {
        if ($handle === null && $onStep === null) {
            return new RunTrace(captureRaw: $captureRaw);
        }

        return new RunTrace(
            captureRaw: $captureRaw,
            onRecord: function (RunStep $step) use ($handle, $onStep): void {
                if ($onStep !== null) {
                    $onStep($step);
                }
                if ($handle !== null) {
                    $this->persister->recordStep($handle, $step);
                }
            },
        );
    }

    /**
     * The single lifecycle ladder (ADR-101; previously copied three times in
     * the playground controller).
     *
     * Every branch both settles the persisted row and marks the run settled so
     * the finally-guard cannot touch it — including a SUCCESSFUL suspension:
     * WAITING_FOR_APPROVAL is non-terminal, so an unguarded finally-settle
     * would flip a resumable run to FAILED and destroy its suspended state.
     * The finally-guard exists for the abandoned-run case (a live-stream
     * observer dying mid-run, mirroring StreamingDispatcher) and costs the
     * settled paths nothing.
     *
     * @param Closure(): ToolLoopResult $loopCall
     */
    private function execute(?AgentRunHandle $handle, RunTrace $trace, Closure $loopCall): AgentRunResult
    {
        $runUuid = $handle !== null ? $handle->uuid : '';
        $settled = false;

        try {
            $result = $loopCall();

            if ($handle !== null) {
                $this->persister->settleCompleted($handle, $result);
            }
            $settled = true;

            return new AgentRunResult(
                outcome: AgentRunOutcome::COMPLETED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                loopResult: $result,
            );
        } catch (ToolApprovalRequiredException $approval) {
            // ADR-084: a called tool requires human approval — control flow,
            // not failure. Persist the suspended state so a later approve()
            // can continue. Both branches below settle the run's fate, so the
            // finally-guard must not run either way.
            $settled = true;

            if ($handle !== null && $this->persister->suspend($handle, $approval->state)) {
                return new AgentRunResult(
                    outcome: AgentRunOutcome::AWAITING_APPROVAL,
                    runUuid: $runUuid,
                    steps: $trace->getSteps(),
                    suspendedState: $approval->state,
                );
            }

            // Fail-closed (ADR-092): an approval-gated tool is side-effecting.
            // Without stored state — the store refused or errored, a concurrent
            // cancel already terminated the row, or the run was never persisted
            // at all (null handle) — there is nothing to resume, so promising
            // an approval flow would strand the client. Applied on EVERY path
            // since ADR-101 (the old code silently ignored a failed
            // re-suspension AND announced awaiting-approval for unpersisted
            // runs).
            if ($handle !== null) {
                $this->persister->settleFailed($handle, $approval);
            }
            $this->logger?->error('Agent run could not be suspended for approval; no resume is possible', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::SUSPEND_FAILED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $approval,
            );
        } catch (GuardrailViolationException|GuardrailApprovalRequiredException $guardrail) {
            // ADR-085/086: a guardrail verdict is a policy outcome, not a
            // failure — and an approval that was required but never obtained
            // is not recorded as an outright denial (ADR-092).
            if ($handle !== null) {
                $this->persister->settlePolicyStopped(
                    $handle,
                    $guardrail,
                    $guardrail instanceof GuardrailApprovalRequiredException
                        ? AgentRunTerminationReason::APPROVAL_DENIED
                        : AgentRunTerminationReason::POLICY_DENIED,
                );
            }
            $settled = true;
            $this->logger?->warning('Agent run blocked by guardrail', ['exception' => $guardrail, 'run' => $runUuid]);

            return new AgentRunResult(
                outcome: $guardrail instanceof GuardrailApprovalRequiredException
                    ? AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED
                    : AgentRunOutcome::GUARDRAIL_BLOCKED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                guardrailClass: $guardrail->guardrail,
                error: $guardrail,
            );
        } catch (Throwable $e) {
            if ($handle !== null) {
                $this->persister->settleFailed($handle, $e);
            }
            $settled = true;
            $this->logger?->error('Agent run failed', ['exception' => $e, 'run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::FAILED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $e,
            );
        } finally {
            // A live-stream observer dying mid-run (client disconnect) can
            // abandon the run before any branch settles it; mark it failed so
            // no run is left stuck RUNNING. Mirrors StreamingDispatcher's
            // finally-block settle. Guarded by $settled: a suspended run is
            // WAITING_FOR_APPROVAL — non-terminal — and settling it here would
            // destroy its resumable state.
            if ($handle !== null && !$settled) {
                $this->persister->settleFailed($handle, new RuntimeException('Agent run did not complete'));
            }
        }
    }
}
