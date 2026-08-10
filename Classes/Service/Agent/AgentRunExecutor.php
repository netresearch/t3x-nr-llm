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
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Agent\Exception\AuditPersistenceFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunCancellationRequestedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLeaseLostException;
use Netresearch\NrLlm\Service\Agent\Exception\WriteWithoutDurableExecutionException;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Drives one agent run from its first round to a settled outcome.
 *
 * This is the lifecycle ladder: the catch order that ADR-084 makes a hard
 * guarantee — {@see ToolApprovalRequiredException} and
 * {@see ToolInputRequiredException} are control flow, not failure, and are
 * caught before the guardrail pair and before the generic Throwable — together
 * with ADR-103's cancellation probe, ADR-104's lease heartbeat and lease-lost
 * stop, and ADR-111's write fence and fail-closed audit.
 *
 * It knows nothing about where the run came from. A request, a claimed queue
 * row and a resumed suspension all reach it as a handle, a trace and a closure
 * that produces the next {@see ToolLoopResult}, so the ordering guarantees hold
 * identically on all three paths — and can be exercised without a queue row, a
 * resume claim or a provider.
 */
final readonly class AgentRunExecutor
{
    public function __construct(
        private ToolLoopServiceInterface $toolLoop,
        private AgentRunPersister $persister,
        private ?LoggerInterface $logger = null,
        // Resolves a run's actor to a live acting backend user for tool
        // authorization (ADR-083). A null falls back to a fresh default
        // instance, the same as in the runtime that builds this executor.
        private ?ActingBackendUserResolverInterface $actingBackendUserResolver = null,
        // Classifies a tool's side effect so a WRITING tool's audit step is
        // fail-closed (ADR-111). A null resolver treats every tool as read-only.
        private ?ToolEffectResolver $toolEffectResolver = null,
    ) {}

    /**
     * The shared execution path behind {@see AgentRuntime::run()} and {@see AgentRuntime::runQueued()}:
     * clamp the round cap, build the trace, drive the ladder.
     *
     * A queued run passes its worker identity as $leaseOwner (so each step
     * boundary renews the lease and detects a reaper reclaim, ADR-104) and a
     * $recover closure that decides retry-vs-dead-letter for a failure. An
     * interactive run() passes neither: it holds no lease and surfaces failures
     * to its caller unchanged.
     *
     * @param (Closure(RunStep): void)|null                             $onStep
     * @param (Closure(Throwable, list<RunStep>): ?AgentRunResult)|null $recover
     */
    public function executeRequest(
        AgentRunRequest $request,
        ?AgentRunHandle $handle,
        ?Closure $onStep,
        ?string $leaseOwner = null,
        ?Closure $recover = null,
    ): AgentRunResult {
        $maxIterations = $request->maxIterations !== null
            ? min($request->maxIterations, AgentRuntime::MAX_ITERATIONS)
            : null;

        $trace = $this->trace($handle, $onStep, $request->captureRaw, $leaseOwner);
        // Resolve the run's explicit actor to a live acting backend user ONCE,
        // identically whether this runs synchronously or in a worker (ADR-083).
        $context = $this->toolContext($request->actor);

        return $this->execute(
            $handle,
            $trace,
            fn(): ToolLoopResult => $this->toolLoop->runLoop(
                $request->messages,
                $request->configuration,
                $context,
                $request->allowedToolNames,
                $request->options,
                $maxIterations,
                $trace,
                $request->augmentation,
            ),
            $recover,
            $leaseOwner,
        );
    }

    /**
     * The resume entry point behind {@see AgentRuntime::approve()} and
     * {@see AgentRuntime::submitInput()}.
     *
     * Both resume paths need the same three things in the same order — a trace
     * over the claimed handle, a tool context built from the RUN OWNER's
     * identity rather than the approver's or the submitter's (ADR-083), and the
     * ladder around the loop call — and they differ only in which
     * {@see ToolLoopServiceInterface} call they make. Taking that call as a
     * factory over the context and the trace keeps the ordering here, where it
     * is stated once, instead of in each caller.
     *
     * A resume carries no recovery: it is the continuation of a suspended run
     * driven by a request, so a failure surfaces to its caller exactly as an
     * interactive run's does. It DOES hold a lease (ADR-141). This is the
     * segment a writing tool executes in — a write suspends before it runs
     * (ADR-134), so the first pass never reaches the tool — and an unleased
     * segment cannot arm the ADR-111 fence around it.
     *
     * @param (Closure(RunStep): void)|null                           $onStep
     * @param Closure(ToolExecutionContext, RunTrace): ToolLoopResult $loopCall
     */
    public function executeResume(
        AgentRunHandle $handle,
        ?Closure $onStep,
        AiActorContext $owner,
        Closure $loopCall,
        ?string $leaseOwner = null,
    ): AgentRunResult {
        $trace   = $this->trace($handle, $onStep, false, $leaseOwner);
        $context = $this->toolContext($owner);

        return $this->execute($handle, $trace, fn(): ToolLoopResult => $loopCall($context, $trace), null, $leaseOwner);
    }

    /**
     * The trace every segment runs under: each recorded step reaches the live
     * observer FIRST (preserving the streaming path's emit-before-persist
     * order — a step is shown even when its persist fails), then the persisted
     * event stream — and then the cancellation probe runs (ADR-103).
     *
     * The probe is what makes {@see cancel()} cooperative: the loop itself
     * stays persistence-unaware (ADR-081), but every step boundary — after a
     * provider response, after each tool execution, before the next round —
     * records a step, and the probe checks the run row there. A run cancelled
     * mid-flight therefore stops before the NEXT provider call or tool
     * execution instead of running to completion with its outcome discarded.
     * The step that just happened is still emitted and persisted: the audit
     * stream stays complete up to the abort point. One indexed row read per
     * step; steps are provider-call-slow, so the cost is negligible.
     *
     * $leaseOwner is the identity of the segment executing this run (ADR-104,
     * ADR-141): a worker's for a queued run, the request's for an interactive
     * one or a resume. After the cancellation check, the lease is renewed under
     * an ownership guard BEFORE the step is persisted. If the renewal affects no
     * row the segment no longer owns the run — the reaper reclaimed it and
     * another owner may hold it now — so it stops WITHOUT writing the step,
     * which would otherwise collide with the new owner's stream.
     *
     * Null is left reachable for the bare test wiring and for a run that could
     * not be persisted at all; it does not mean "skip the fence". The
     * before-tool guard is installed either way and REFUSES a side-effecting
     * tool it cannot fence.
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    private function trace(?AgentRunHandle $handle, ?Closure $onStep, bool $captureRaw, ?string $leaseOwner = null): RunTrace
    {
        // The write guard below is installed even when there is nothing to
        // record: a run that could not be persisted at all (begin() returned
        // null) has no row to fence against, and that is precisely the case the
        // guard must refuse rather than the case it may skip.
        return new RunTrace(
            captureRaw: $captureRaw,
            onRecord: !$handle instanceof AgentRunHandle && !$onStep instanceof Closure ? null : function (RunStep $step) use ($handle, $onStep, $leaseOwner): void {
                // The live observer sees the step FIRST (emit-before-persist),
                // so a step is shown even when its persistence or the checks
                // below abort the loop.
                if ($onStep instanceof Closure) {
                    $onStep($step);
                }

                if (!$handle instanceof AgentRunHandle) {
                    return;
                }

                // A single indexed read drives the cancellation probe (ADR-103).
                // findRun is fail-soft (null on a store hiccup), so a read
                // failure can never fabricate a cancellation.
                if ($this->persister->findRun($handle->uuid)?->statusEnum() === AgentRunStatus::CANCELLED) {
                    // Persist the step that already happened, then stop: the
                    // audit stream stays complete up to the abort point. A
                    // writing tool whose audit cannot be stored fails the run
                    // (ADR-111) even mid-cancel — an unrecorded mutation is the
                    // more serious condition than the cancellation.
                    $this->recordStepFailClosedForWrites($handle, $step);

                    throw RunCancellationRequestedException::forRun($handle->uuid);
                }

                // Heartbeat + lease-lost guard for a worker run (ADR-104). The
                // renewal's ownership guard is the atomic check: 0 rows means
                // the run was reclaimed/re-claimed/terminated — stop WITHOUT
                // recording the step, so a zombie worker cannot append an event
                // whose sequence collides with the new owner's stream. A completed
                // WRITE step also CLEARS the in-flight fence set before it ran
                // (ADR-111) in the same guarded write.
                if ($leaseOwner !== null && !$this->renewOrClearFence($handle, $leaseOwner, $step)) {
                    throw RunLeaseLostException::forRun($handle->uuid);
                }

                $this->recordStepFailClosedForWrites($handle, $step);
            },
            onBeforeTool: function (string $toolName) use ($handle, $leaseOwner): void {
                // Read-only tools need no fence — repeating them is always safe.
                $effect = $this->toolEffectResolver?->effectFor($toolName) ?? ToolEffect::READ_ONLY;
                if (!$effect->isWrite()) {
                    return;
                }

                // The universal write guard (ADR-141): a side effect may only run
                // in a segment that can fence it. Fencing needs a persisted run
                // AND a lease this segment owns; a segment holding neither cannot
                // stamp the effect, so the write is refused BEFORE it happens
                // rather than executed unfenced. This hook is installed on every
                // path — an entry point that forgets to claim its run fails
                // closed here instead of silently skipping the fence.
                if (!$handle instanceof AgentRunHandle || $leaseOwner === null) {
                    throw WriteWithoutDurableExecutionException::forTool(
                        $handle instanceof AgentRunHandle ? $handle->uuid : '',
                        $toolName,
                    );
                }

                // Fence the WRITE (ADR-111): stamp its effect and renew the lease
                // so a reap mid non-idempotent-write dead-letters instead of
                // retrying. A lost lease stops the segment before the side
                // effect, exactly like the heartbeat guard.
                if (!$this->persister->markPendingEffect($handle, $leaseOwner, $effect->value, time() + AgentRuntime::LEASE_SECONDS)) {
                    throw RunLeaseLostException::forRun($handle->uuid);
                }
            },
        );
    }

    /**
     * Build the tool-execution context for a run from its explicit actor: the
     * one place the actor becomes a live acting backend user, identically on the
     * synchronous and worker paths (ADR-083), so no tool reads the ambient
     * `$GLOBALS['BE_USER']`.
     */
    private function toolContext(AiActorContext $actor): ToolExecutionContext
    {
        $resolver = $this->actingBackendUserResolver ?? new ActingBackendUserResolver();

        return new ToolExecutionContext($actor, $resolver->resolve($actor));
    }

    /**
     * Renew the lease at a step boundary and, for a completed WRITE step, clear
     * the in-flight fence its {@see RunTrace::beforeToolExecution()} set — both in
     * one ownership-guarded write (ADR-111). Returns false when the worker has
     * lost the run, so the caller stops before recording the step.
     */
    private function renewOrClearFence(AgentRunHandle $handle, string $leaseOwner, RunStep $step): bool
    {
        $leaseExpires = time() + AgentRuntime::LEASE_SECONDS;
        if ($this->stepEffect($step)->isWrite()) {
            return $this->persister->markPendingEffect($handle, $leaseOwner, '', $leaseExpires);
        }

        return $this->persister->renewLease($handle, $leaseOwner, $leaseExpires);
    }

    /**
     * Persist a step, failing the run when a WRITING tool's audit event could
     * not be stored (ADR-111). Read-only and non-tool steps stay fail-soft: a
     * store hiccup is logged inside {@see AgentRunPersister::recordStep()} and
     * the run continues. A write is different — an unrecorded mutation must not
     * be waved through, so it throws {@see AuditPersistenceFailedException}
     * (non-retryable: the write already ran once).
     */
    private function recordStepFailClosedForWrites(AgentRunHandle $handle, RunStep $step): void
    {
        $persisted = $this->persister->recordStep($handle, $step);
        if (!$persisted && $this->stepEffect($step)->isWrite()) {
            throw AuditPersistenceFailedException::forRun($handle->uuid, $step->toolName ?? '');
        }
    }

    /**
     * The side effect of the tool a step recorded (ADR-111). Only KIND_TOOL
     * steps carry an effect; everything else is read-only. A tool name that no
     * longer resolves is treated as the strictest effect (fail-closed), and a
     * runtime built without the resolver (bare positional test wiring only)
     * treats every tool as read-only.
     */
    private function stepEffect(RunStep $step): ToolEffect
    {
        if ($step->kind !== RunStep::KIND_TOOL || !$this->toolEffectResolver instanceof ToolEffectResolver) {
            return ToolEffect::READ_ONLY;
        }

        return $this->toolEffectResolver->effectFor($step->toolName ?? '');
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
     * A queued run passes a $recover closure (ADR-104): in the generic failure
     * arm, before the default settleFailed, it decides retry-vs-dead-letter and,
     * when it handles the failure, returns the outcome that replaces FAILED.
     *
     * @param Closure(): ToolLoopResult                                 $loopCall
     * @param (Closure(Throwable, list<RunStep>): ?AgentRunResult)|null $recover
     */
    private function execute(?AgentRunHandle $handle, RunTrace $trace, Closure $loopCall, ?Closure $recover = null, ?string $ownerGuard = null): AgentRunResult
    {
        $runUuid = $handle instanceof AgentRunHandle ? $handle->uuid : '';
        $settled = false;

        try {
            $result = $loopCall();

            if ($handle instanceof AgentRunHandle) {
                $settledOk = $this->persister->settleCompleted($handle, $result, $ownerGuard);
                // Ownership-guarded on the queued path: if the run was reclaimed
                // to another worker mid-loop, this completion must not overwrite
                // its state — stop as LEASE_LOST rather than reporting COMPLETED.
                if ($ownerGuard !== null && !$settledOk) {
                    $settled = true;

                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $trace->getSteps());
                }
            }

            $settled = true;

            return new AgentRunResult(
                outcome: AgentRunOutcome::COMPLETED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                loopResult: $result,
            );
        } catch (RunCancellationRequestedException $cancelled) {
            // ADR-103: the operator's cancel already won the guarded terminal
            // transition — the row IS CANCELLED and its late settle would be
            // discarded anyway, so none is attempted. Control flow, not
            // failure: the loop stopped cooperatively at a step boundary.
            $settled = true;
            $this->logger?->info('Agent run stopped cooperatively after being cancelled', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::CANCELLED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $cancelled,
            );
        } catch (ToolApprovalRequiredException $approval) {
            // ADR-084: a called tool requires human approval — control flow,
            // not failure. Persist the suspended state so a later approve()
            // can continue. Both branches below settle the run's fate, so the
            // finally-guard must not run either way.
            $settled = true;

            if ($handle instanceof AgentRunHandle && $this->persister->suspend($handle, $approval->state)) {
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
            if ($handle instanceof AgentRunHandle) {
                // Ownership-guarded so a reclaimed queued run is not clobbered by
                // this worker's fail-closed settle.
                $this->persister->settleFailed($handle, $approval, $ownerGuard);
            }

            $this->logger?->error('Agent run could not be suspended for approval; no resume is possible', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::SUSPEND_FAILED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $approval,
            );
        } catch (ToolInputRequiredException $input) {
            // ADR-105: a called tool requires typed user input — control flow,
            // not failure (the input sibling of the approval arm above). Must be
            // caught before the guardrail pair and the generic Throwable so a
            // suspension is never recorded as a failed run. Both branches settle
            // the run's fate, so $settled=true keeps the finally-guard off — a
            // successfully-suspended WAITING_FOR_INPUT row is non-terminal and
            // must not be flipped to FAILED.
            $settled = true;

            if ($handle instanceof AgentRunHandle && $this->persister->suspendForInput($handle, $input->state)) {
                return new AgentRunResult(
                    outcome: AgentRunOutcome::AWAITING_INPUT,
                    runUuid: $runUuid,
                    steps: $trace->getSteps(),
                    suspendedState: $input->state,
                );
            }

            // Fail-closed (ADR-092/105): without stored state — the store
            // refused or errored, a concurrent cancel terminated the row, or the
            // run was never persisted (null handle) — there is nothing to resume,
            // so promising an input flow would strand the client.
            if ($handle instanceof AgentRunHandle) {
                // Ownership-guarded so a reclaimed queued run is not clobbered by
                // this worker's fail-closed settle.
                $this->persister->settleFailed($handle, $input, $ownerGuard);
            }

            $this->logger?->error('Agent run could not be suspended for input; no resume is possible', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::SUSPEND_FAILED,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $input,
            );
        } catch (GuardrailViolationException|GuardrailApprovalRequiredException $guardrail) {
            // ADR-085/086: a guardrail verdict is a policy outcome, not a
            // failure — and an approval that was required but never obtained
            // is not recorded as an outright denial (ADR-092).
            if ($handle instanceof AgentRunHandle) {
                $settledOk = $this->persister->settlePolicyStopped(
                    $handle,
                    $guardrail,
                    $guardrail instanceof GuardrailApprovalRequiredException
                        ? AgentRunTerminationReason::APPROVAL_DENIED
                        : AgentRunTerminationReason::POLICY_DENIED,
                    $ownerGuard,
                );
                // Ownership-guarded on the queued path: a run reclaimed to
                // another worker must not be flipped to a policy-stopped terminal
                // by this zombie worker — stop as LEASE_LOST.
                if ($ownerGuard !== null && !$settledOk) {
                    $settled = true;
                    $this->logger?->notice('Guardrail-stopped queued run was reclaimed; leaving it to its new owner', ['run' => $runUuid]);

                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $trace->getSteps(), error: $guardrail);
                }
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
        } catch (RunLeaseLostException $leaseLost) {
            // ADR-104: the reaper reclaimed this run (or a cancel/settle won) and
            // it belongs to another worker now. Stop WITHOUT settling — any
            // settle here could destroy the new owner's in-flight state. Not a
            // failure of the run; a loss of ownership by this worker.
            $settled = true;
            $this->logger?->info('Agent run worker lost its lease; stopping without settling', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::LEASE_LOST,
                runUuid: $runUuid,
                steps: $trace->getSteps(),
                error: $leaseLost,
            );
        } catch (Throwable $e) {
            // A queued run's recover closure decides retry-vs-dead-letter and,
            // when it handles the failure, settles the row itself and returns
            // the replacement outcome. Only an interactive run (no recover) or a
            // recover that declines (null) falls through to the default settle.
            if ($recover instanceof Closure) {
                $recovery = $recover($e, $trace->getSteps());
                if ($recovery instanceof AgentRunResult) {
                    $settled = true;

                    return $recovery;
                }
            }

            if ($handle instanceof AgentRunHandle) {
                $settledOk = $this->persister->settleFailed($handle, $e, $ownerGuard);
                if ($ownerGuard !== null && !$settledOk) {
                    $settled = true;
                    $this->logger?->notice('Failed queued run was reclaimed; leaving it to its new owner', ['run' => $runUuid]);

                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $trace->getSteps(), error: $e);
                }
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
            // WAITING_FOR_APPROVAL or WAITING_FOR_INPUT — both non-terminal — and
            // settling it here would destroy its resumable state.
            if ($handle instanceof AgentRunHandle && !$settled) {
                // Ownership-guarded so this safety-net settle cannot clobber a
                // queued run the reaper reclaimed to another worker.
                $this->persister->settleFailed($handle, new RuntimeException('Agent run did not complete'), $ownerGuard);
            }
        }
    }
}
