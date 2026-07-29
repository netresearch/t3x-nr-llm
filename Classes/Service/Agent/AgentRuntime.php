<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
        // Puts a run on the queue and picks it back up in a worker (ADR-102).
        // Optional like the collaborators above; a null builds one over the
        // same persister, codec, executor, recovery, logger and bus.
        private ?QueuedRunCoordinator $queueCoordinator = null,
        // Picks a suspended run back up on an approval or a submitted input
        // (ADR-084/105). Optional like the collaborators above; a null builds
        // one over the same persister, configuration repository, tool loop,
        // executor and schema validator.
        private ?ResumeCoordinator $resumeCoordinator = null,
    ) {}

    /**
     * The resume coordinator, falling back to one built from this runtime's own
     * collaborators when none was injected.
     */
    private function resumeCoordinator(): ResumeCoordinator
    {
        return $this->resumeCoordinator ?? new ResumeCoordinator(
            $this->persister,
            $this->configurationRepository,
            $this->toolLoop,
            $this->runExecutor(),
            $this->schemaValidator,
        );
    }

    /**
     * The queue coordinator, falling back to one built from this runtime's own
     * collaborators when none was injected.
     */
    private function queueCoordinator(): QueuedRunCoordinator
    {
        return $this->queueCoordinator ?? new QueuedRunCoordinator(
            $this->persister,
            $this->requestCodec(),
            $this->runExecutor(),
            $this->failureRecovery(),
            $this->logger,
            $this->messageBus,
        );
    }

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
        return $this->queueCoordinator()->enqueue($request);
    }

    public function runQueued(string $runUuid, ?Closure $onStep = null): ?AgentRunResult
    {
        return $this->queueCoordinator()->runQueued($runUuid, $onStep);
    }

    public function approve(AiActorContext $actor, string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult
    {
        return $this->resumeCoordinator()->approve($actor, $runUuid, $decision, $onStep);
    }

    public function submitInput(AiActorContext $actor, string $runUuid, InputSubmission $submission, ?Closure $onStep = null): AgentRunResult
    {
        return $this->resumeCoordinator()->submitInput($actor, $runUuid, $submission, $onStep);
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
