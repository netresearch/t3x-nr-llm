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
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;

/**
 * The public application service for persisted agent runs (ADR-101):
 * execute, enqueue, suspend, resume, cancel and observe through one surface.
 * Playground, CLI, queue workers and editor actions share this lifecycle.
 *
 * CONSUMER interface: call it; do not implement or decorate it outside nr_llm.
 * Methods and AgentRunOutcome cases may be added in minor releases, so
 * exhaustive matches need a default arm. ToolLoopServiceInterface remains
 * public for consumers that need the bare loop without persistence or approval.
 *
 * @api
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
    public function approve(AiActorContext $actor, string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult;

    /**
     * Submit typed input to a WAITING_FOR_INPUT run and continue it (ADR-105).
     * The declared schema and turn digest are checked before claiming, so invalid
     * input does not consume the claim. The valid submission is claimed atomically,
     * recorded as an INPUT event, overlaid only onto schema-declared argument keys,
     * and resumed under the initiating actor with a settled result.
     *
     * The initiator, an administrator or an actor with the approval grant may
     * submit. Every pending tool is re-authorized against the owner's live rights.
     * Submitted values remain untrusted content; structural validation does not
     * sanitize their content or make them instructions.
     *
     * @param (Closure(RunStep): void)|null $onStep as in {@see self::run()}
     *
     * @throws AgentRuntimeException for an invalid request before execution:
     *                               RunNotAwaitingInput, RunConfigurationGone,
     *                               CorruptSuspendedState, InvalidInputSubmission,
     *                               RunStateUnavailable, RunAlreadyResuming
     */
    public function submitInput(AiActorContext $actor, string $runUuid, InputSubmission $submission, ?Closure $onStep = null): AgentRunResult;

    /**
     * Cancel a queued, running or waiting run. True for the guarded winner;
     * false for an unknown or already terminal run.
     *
     * A late settle cannot overwrite cancellation. The runtime observes the
     * cancelled row at each step boundary and stops before further work (ADR-103).
     * Supported MCP transports also receive the run's cancellation signal and can
     * abort an in-flight transfer (ADR-190). Calls without that capability finish
     * their current step before the cooperative boundary stops the run.
     */
    public function cancel(AiActorContext $actor, string $runUuid): bool;

    /**
     * Cancel a run only while it waits for a human — WAITING_FOR_APPROVAL or
     * WAITING_FOR_INPUT — in one guarded transition (ADR-214, item 10).
     *
     * A chat withdraws a waiting proposal with it when a new message arrives:
     * if a decision already released the run, the run is QUEUED or RUNNING,
     * this call loses, and the result says which status the run has, so the
     * caller can put its own state back instead of stopping a write somebody
     * approved. {@see self::cancel()} also ends a queued or running run and
     * stays the operator's tool.
     *
     * Only the run's initiator or an administrator may call it; a service
     * account may not, whatever its scopes, because withdrawing a person's
     * proposal is that person's act. For anyone else, and for an unknown run,
     * the result is "not cancelled" with no status.
     */
    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): GuardedCancelResult;

    /**
     * The persisted event stream of a run, ordered by sequence ascending —
     * only events with sequence > $afterSequence, so a poller can page.
     * Empty for an unknown run (indistinguishable from a run with no events;
     * use {@see self::status()} to tell the two apart).
     *
     * @return list<AgentRunEvent>
     */
    public function events(AiActorContext $actor, string $runUuid, int $afterSequence = -1): array;

    /**
     * The persisted run row, or null when unknown. The suspended-state
     * transcript is stripped: it is stored verbatim for resume and bypasses
     * the privacy filter every event goes through (ADR-064), so it is not
     * part of the status surface.
     */
    public function status(AiActorContext $actor, string $runUuid): ?AgentRun;
}
