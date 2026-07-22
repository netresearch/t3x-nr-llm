<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;

/**
 * The public application service for agent runs (ADR-101): begin, execute,
 * persist, suspend for approval, resume, cancel and observe an agent run
 * through one surface, so every consumer — the playground UI, CLI commands,
 * and later scheduler/queue workers, editor actions and review queues — gets
 * the identical, fail-closed lifecycle instead of re-assembling it from the
 * loop and the persister.
 *
 * CONSUMER interface: call it, do not implement or decorate it outside
 * nr_llm. Methods and {@see \Netresearch\NrLlm\Domain\Enum\AgentRunOutcome}
 * cases may be added in minor releases (the queue epic will add asynchronous
 * execution), so exhaustive matches need a default arm. The lower-level
 * {@see \Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface} stays public
 * for consumers that want the bare loop without persistence or approval; this
 * runtime is the preferred surface.
 */
interface AgentRuntimeInterface
{
    /**
     * Execute one agent run synchronously: open a persisted run, drive the tool
     * loop, and settle the row to match the outcome. Never throws for a run
     * outcome — the returned result is already settled (completion, suspension
     * for approval, guardrail block, or failure).
     *
     * A non-null {@see AgentRunRequest::$maxIterations} is clamped to
     * {@see AgentRuntime::MAX_ITERATIONS}; null keeps the loop's own default.
     *
     * @param (Closure(RunStep): void)|null $onStep fired for each step the moment it
     *                                              is recorded (before it is persisted),
     *                                              so a caller can stream steps live
     */
    public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult;

    /**
     * Enqueue an agent run for asynchronous execution (ADR-102): persist a
     * QUEUED row carrying the serialised request, then dispatch a wake-up
     * message on the message bus. Returns the run uuid for status polling
     * ({@see self::status()} / {@see self::events()}).
     *
     * Transport is the operator's choice (TYPO3 messenger routing): on the
     * default synchronous transport the run executes in-process before this
     * method returns; routed to the doctrine transport it executes inside
     * ``messenger:consume``. Fail-closed: when the row cannot be stored or the
     * message cannot be dispatched, no QUEUED run is left behind.
     *
     * @throws Exception\RunEnqueueFailedException
     */
    public function enqueue(AgentRunRequest $request): string;

    /**
     * Claim and execute a queued run (ADR-102) — the worker entry point behind
     * {@see Queue\AgentRunQueuedHandler}. Atomically claims the QUEUED row
     * (exactly one worker wins; a cancelled or already-claimed run returns
     * null), rehydrates the stored request and drives the same fail-closed
     * lifecycle as {@see self::run()}. Never throws for a run outcome; a
     * rehydration failure settles the run FAILED and is returned as such.
     *
     * @param (Closure(RunStep): void)|null $onStep as in {@see self::run()}
     *
     * @return AgentRunResult|null null when the run was not claimable
     */
    public function runQueued(string $runUuid, ?Closure $onStep = null): ?AgentRunResult;

    /**
     * Decide a run suspended for human approval (ADR-084) and synchronously
     * continue it: execute the pending tool calls when approved (refuse them
     * into the transcript when not), then re-enter the loop. The continuation
     * may itself suspend again, and — like {@see self::run()} — always comes
     * back as a settled result.
     *
     * The decision is claimed atomically (two concurrent approvals cannot both
     * execute the gated calls) and persisted as an APPROVAL event in the run's
     * stream.
     *
     * @param (Closure(RunStep): void)|null $onStep as in {@see self::run()}
     *
     * @throws AgentRuntimeException when the request is invalid before any
     *                               execution: RunNotAwaitingApproval,
     *                               RunConfigurationGone, CorruptSuspendedState,
     *                               RunStateUnavailable, RunAlreadyResuming
     */
    public function approve(string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult;

    /**
     * Submit typed input for a run suspended WAITING_FOR_INPUT (ADR-105) and
     * synchronously continue it. The input sibling of {@see self::approve()}:
     * the submission is validated against the tool's declared schema BEFORE the
     * run is claimed, so an invalid submission is rejected without consuming the
     * claim and the user can resubmit while the run stays WAITING_FOR_INPUT.
     * A valid submission is claimed atomically (two concurrent submissions
     * cannot both resume), persisted as an INPUT event, overlaid onto the target
     * tool's arguments (bounded to the schema-declared keys), and the loop
     * re-entered — coming back as a settled result like {@see self::run()}.
     *
     * Trust boundary: the submitted values are UNTRUSTED content that flow into
     * the tool's arguments and back into the model context; the submit entry
     * point is admin-gated, which is the injection mitigation (structure-only
     * schema validation does not sanitise content).
     *
     * @param (Closure(RunStep): void)|null $onStep as in {@see self::run()}
     *
     * @throws AgentRuntimeException when the request is invalid before any
     *                               execution: RunNotAwaitingInput,
     *                               RunConfigurationGone, CorruptSuspendedState,
     *                               InvalidInputSubmission, RunStateUnavailable,
     *                               RunAlreadyResuming
     */
    public function submitInput(string $runUuid, InputSubmission $submission, ?Closure $onStep = null): AgentRunResult;

    /**
     * Cancel a run that is still queued, running or awaiting a decision. True
     * when this call cancelled it; false when the run is unknown or already
     * terminal (the guarded transition decides, so two concurrent cancels
     * cannot both win). Cancellation is a persistence-level fence — a late
     * settle from an in-flight loop is discarded — AND cooperative (ADR-103):
     * a loop running under this runtime notices the cancelled row at its next
     * step boundary and stops before the next provider call or tool execution,
     * surfacing {@see \Netresearch\NrLlm\Domain\Enum\AgentRunOutcome::CANCELLED}
     * to whoever drove the run. A step already in flight (a provider call, a
     * tool) runs to its boundary — cancellation is not a signal.
     */
    public function cancel(string $runUuid): bool;

    /**
     * The persisted event stream of a run, ordered by sequence ascending —
     * only events with sequence > $afterSequence, so a poller can page.
     * Empty for an unknown run (indistinguishable from a run with no events;
     * use {@see self::status()} to tell the two apart).
     *
     * @return list<AgentRunEvent>
     */
    public function events(string $runUuid, int $afterSequence = -1): array;

    /**
     * The persisted run row, or null when unknown. The suspended-state
     * transcript is stripped: it is stored verbatim for resume and bypasses
     * the privacy filter every event goes through (ADR-064), so it is not
     * part of the status surface.
     */
    public function status(string $runUuid): ?AgentRun;
}
