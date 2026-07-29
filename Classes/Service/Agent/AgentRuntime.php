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
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\InputSchema;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
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

    /**
     * How long a worker's claim on a queued run is presumed live (ADR-102).
     * Written at claim time and renewed at every step boundary while the worker
     * runs (ADR-104 heartbeat); the stale-run reaper reclaims a run whose lease
     * has expired.
     */
    public const LEASE_SECONDS = 900;

    /**
     * How many times a queued run may be requeued before it dead-letters
     * (ADR-104). A shared budget for both requeue sources — a retryable failure
     * and a stale-lease reclaim — so a deterministically crashing run cannot
     * loop forever through the queue.
     */
    public const MAX_REQUEUES = 3;

    /**
     * Base backoff before a requeued run is retried, in milliseconds (ADR-104).
     * The delay grows exponentially with the requeue count, capped by
     * {@see self::REQUEUE_BACKOFF_CAP_MS}. Honoured by the doctrine transport
     * (available_at); the sync transport ignores it and retries in-process,
     * bounded by {@see self::MAX_REQUEUES}.
     */
    public const REQUEUE_BACKOFF_MS = 30_000;

    /** Ceiling on the exponential requeue backoff, in milliseconds (ADR-104). */
    public const REQUEUE_BACKOFF_CAP_MS = 600_000;

    public function __construct(
        private ToolLoopServiceInterface $toolLoop,
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurationRepository,
        private ?LoggerInterface $logger = null,
        private ?MessageBusInterface $messageBus = null,
        // Validates a submitted input against a tool's declared schema (ADR-105).
        // Optional in the ctor only so the lean test wiring and the positional
        // construction sites keep working; submitInput() always validates,
        // falling back to a fresh stateless validator when none was injected.
        private ?JsonSchemaValidator $schemaValidator = null,
        // Resolves the run's actor to a live acting backend user for tool
        // authorization (ADR-083). Optional in the ctor only for the positional
        // test wiring; production autowires the real resolver, and a null falls
        // back to a fresh default instance.
        private ?ActingBackendUserResolverInterface $actingBackendUserResolver = null,
        // Classifies a tool's side effect so a WRITING tool's audit step is
        // fail-closed (ADR-111). Optional only for the positional test wiring;
        // production autowires it. A null resolver treats every tool as
        // read-only — safe today (no builtin writes) and only reachable in bare
        // test construction, never in the autowired runtime.
        private ?ToolEffectResolver $toolEffectResolver = null,
        // Serialises a request into, and back out of, the queued row (ADR-102).
        // Optional in the ctor only for the positional test wiring, like the
        // collaborators above; production autowires it, and a null builds a
        // codec over the same configuration repository this runtime already
        // holds — without forced skills or snippets, which only the queue path
        // resolves.
        private ?AgentRunRequestCodec $requestCodec = null,
        // Decides retry-vs-dead-letter for a failed queued run (ADR-104).
        // Optional for the same reason as the collaborators above; a null
        // builds one over the persister, logger and bus this runtime holds,
        // which is exactly what it was constructed with before.
        private ?QueuedRunFailureRecovery $failureRecovery = null,
        // Drives a run from its first round to a settled outcome (ADR-084's
        // catch order, ADR-103's cancellation probe, ADR-104's lease, ADR-111's
        // write fence). Optional for the same reason as the collaborators
        // above; a null builds one over the tool loop, persister, logger and
        // the two resolvers this runtime already holds.
        private ?AgentRunExecutor $runExecutor = null,
    ) {}

    /**
     * The executor for a run's lifecycle, falling back to one built from this
     * runtime's own collaborators when none was injected.
     */
    private function runExecutor(): AgentRunExecutor
    {
        return $this->runExecutor ?? new AgentRunExecutor(
            $this->toolLoop,
            $this->persister,
            $this->logger,
            $this->actingBackendUserResolver,
            $this->toolEffectResolver,
        );
    }

    /**
     * The recovery for a failed queued run, falling back to one built from this
     * runtime's own collaborators when none was injected.
     */
    private function failureRecovery(): QueuedRunFailureRecovery
    {
        return $this->failureRecovery ?? new QueuedRunFailureRecovery($this->persister, $this->logger, $this->messageBus);
    }

    /**
     * The codec for the queued-request payload, falling back to a bare instance
     * when none was injected.
     */
    private function requestCodec(): AgentRunRequestCodec
    {
        return $this->requestCodec ?? new AgentRunRequestCodec($this->configurationRepository);
    }

    public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult
    {
        $handle = $this->persister->begin($request->configuration, $request->actor->backendUserUid);

        return $this->runExecutor()->executeRequest($request, $handle, $onStep);
    }

    public function enqueue(AgentRunRequest $request): string
    {
        if ($this->messageBus === null) {
            throw RunEnqueueFailedException::forRun('');
        }

        try {
            $requestJson = json_encode(
                $this->requestCodec()->dehydrate($request),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $e) {
            // A non-encodable payload value (INF/NAN in a raw message array)
            // must surface as the interface's documented failure type, not a
            // bare JsonException. Nothing was stored yet — no cleanup needed.
            $this->logger?->error('Agent run request could not be serialised for the queue', ['exception' => $e]);

            throw RunEnqueueFailedException::forRun('');
        }

        // Fail-closed, unlike run(): a live run can proceed unpersisted, but a
        // queued run without a stored row simply does not exist.
        $handle = $this->persister->enqueue($request->configuration, $request->actor->backendUserUid, $requestJson);
        if ($handle === null) {
            throw RunEnqueueFailedException::forRun('');
        }

        try {
            // The wake-up call. On the default SyncTransport the run executes
            // right here; routed to the doctrine transport it executes in
            // messenger:consume. The claim makes a duplicate dispatch harmless.
            $this->messageBus->dispatch(new AgentRunQueuedMessage($handle->uuid));
        } catch (Throwable $e) {
            // On an async transport a failed dispatch would strand the row
            // QUEUED forever (no message will ever arrive) — settle it failed
            // so no orphan is left behind. On the sync transport a handler
            // failure never reaches here (runQueued() settles outcomes itself
            // and does not throw), so this is genuinely the transport failing.
            $this->persister->settleFailed($handle, $e);
            $this->logger?->error('Queued agent run could not be dispatched; the run was failed', ['run' => $handle->uuid, 'exception' => $e]);

            throw RunEnqueueFailedException::forRun($handle->uuid);
        }

        return $handle->uuid;
    }

    public function runQueued(string $runUuid, ?Closure $onStep = null): ?AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null || $run->statusEnum() !== AgentRunStatus::QUEUED || $run->queuedRequest === null) {
            return null;
        }

        // Exactly one worker wins the guarded QUEUED -> RUNNING transition; a
        // run cancelled while waiting is terminal and unclaimable (ADR-102).
        $workerIdentity = $this->workerIdentity();
        if (!$this->persister->claimQueued($run, $workerIdentity, time() + self::LEASE_SECONDS)) {
            return null;
        }

        // Resolve the event-stream position from a FRESH row AFTER the claim.
        // A first execution has no events (MAX = -1 -> sequence 0, identical to
        // before ADR-104); a REQUEUED run carries the prior attempt's events, so
        // starting at 0 would reuse sequences and corrupt the stream. Fail-closed
        // like approve(): a null position settles the run FAILED rather than
        // stranding it RUNNING (the claim is already won).
        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed !== null ? $this->persister->resumeHandle($claimed) : null;
        if ($handle === null) {
            $fallback = new AgentRunHandle($run->uid, $run->uuid);
            $this->persister->settleFailed(
                $fallback,
                new RuntimeException(sprintf('The event-stream position of queued run %s could not be determined after the claim', $runUuid), 1784900002),
            );
            $this->logger?->error('Queued agent run position could not be resolved after the claim; the run was failed', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::FAILED,
                runUuid: $runUuid,
                steps: [],
                error: new RuntimeException('The event-stream position could not be determined after the queue claim', 1784900002),
            );
        }

        try {
            $request = $this->requestCodec()->rehydrate($run);
        } catch (Throwable $e) {
            $this->persister->settleFailed($handle, $e);
            $this->logger?->error('Queued agent run could not be rehydrated; the run was failed', ['run' => $runUuid, 'exception' => $e]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::FAILED,
                runUuid: $runUuid,
                steps: [],
                error: $e,
            );
        }

        // The heartbeat renews the lease under this worker's identity, and the
        // recover closure decides retry-vs-dead-letter for a failure (ADR-104).
        $recover = function (Throwable $e, array $steps) use ($handle, $runUuid, $workerIdentity): AgentRunResult {
            /** @var list<RunStep> $steps the ladder always passes the accumulated step list */
            return $this->failureRecovery()->recover($handle, $runUuid, $workerIdentity, $e, $steps);
        };

        return $this->runExecutor()->executeRequest($request, $handle, $onStep, $workerIdentity, $recover);
    }

    /**
     * Which worker claimed a queued run — host + pid, for lease diagnostics.
     */
    private function workerIdentity(): string
    {
        $host = gethostname();

        return substr(($host !== false ? $host : 'unknown') . ':' . getmypid(), 0, 64);
    }

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
        return $this->runExecutor()->executeResume(
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
        return $this->runExecutor()->executeResume(
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

    public function cancel(AiActorContext $actor, string $runUuid): bool
    {
        // Authorised like approve/submitInput: only the run's owner, an admin or
        // a service account may cancel it (a guessed uuid is never enough).
        $run = $this->persister->findRun($runUuid);
        if ($run === null || !$actor->mayActOnRun($run, ServiceAccountScope::AGENT_CANCEL)) {
            return false;
        }

        return $this->persister->cancel($runUuid);
    }

    public function events(AiActorContext $actor, string $runUuid, int $afterSequence = -1): array
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null || !$actor->mayActOnRun($run, ServiceAccountScope::AGENT_READ)) {
            return [];
        }

        // Filtered in SQL — a poller pages without re-hydrating the history.
        return $this->persister->findEvents($run->uid, $afterSequence);
    }

    public function status(AiActorContext $actor, string $runUuid): ?AgentRun
    {
        $run = $this->persister->findRun($runUuid);
        if ($run === null || !$actor->mayActOnRun($run, ServiceAccountScope::AGENT_READ)) {
            return null;
        }

        // The raw suspended transcript bypasses the privacy filter (it must —
        // resume needs it verbatim); the status surface must not expose it.
        return $run->withoutSuspendedState();
    }

    /**
     * Whether a run may be retried given the effect fence in flight when it
     * failed (ADR-111). Empty (no tool fenced) or an unrecognised value is safe;
     * only a fenced NON_IDEMPOTENT_WRITE — a side effect that must not repeat —
     * blocks the retry. Shared by the in-process recover path and the reaper.
     */
    public static function mayRetryAfterFence(string $pendingEffect): bool
    {
        return ToolEffect::tryFrom($pendingEffect)?->isSafeToRetry() ?? true;
    }

}
