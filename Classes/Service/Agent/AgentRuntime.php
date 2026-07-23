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
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use Netresearch\NrLlm\Service\Agent\Exception\AuditPersistenceFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunCancellationRequestedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLeaseLostException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\InputSchema;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
        private ?SkillRepository $skillRepository = null,
        private ?PromptSnippetRepository $promptSnippetRepository = null,
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
    ) {}

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

    public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult
    {
        $handle = $this->persister->begin($request->configuration, $request->actor->backendUserUid);

        return $this->executeRequest($request, $handle, $onStep);
    }

    public function enqueue(AgentRunRequest $request): string
    {
        if ($this->messageBus === null) {
            throw RunEnqueueFailedException::forRun('');
        }

        try {
            $requestJson = json_encode(
                $this->dehydrateRequest($request),
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
            $request = $this->rehydrateRequest($run);
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
            return $this->recoverQueuedFailure($handle, $runUuid, $workerIdentity, $e, $steps);
        };

        return $this->executeRequest($request, $handle, $onStep, $workerIdentity, $recover);
    }

    /**
     * Decide the fate of a FAILED queued run (ADR-104), invoked from the ladder
     * BEFORE the default settleFailed. Returns the outcome that replaces the
     * generic FAILED — for a queued run every branch settles the row itself, so
     * the ladder never falls through to PROVIDER_FAILED. Fully fail-soft: it
     * must never throw — {@see runQueued()} does not throw once the claim is
     * won — so a failure of the recovery machinery itself dead-letters the run.
     *
     * @param list<RunStep> $steps
     */
    private function recoverQueuedFailure(AgentRunHandle $handle, string $runUuid, string $workerIdentity, Throwable $e, array $steps): AgentRunResult
    {
        try {
            $class = FailureClassifier::classify($e);

            // A class retrying cannot fix (auth, config, a 4xx client error):
            // dead-letter immediately, distinct from PROVIDER_FAILED so it never
            // reads as retryable.
            if (!$class->isRetryable()) {
                // Ownership-guarded like the requeue branch below: if this worker
                // no longer owns the run (reaper reclaimed it), do NOT settle —
                // return LEASE_LOST and leave the row to its current owner.
                if (!$this->persister->settleDeadLettered($handle, $e, AgentRunTerminationReason::NOT_RETRYABLE, $workerIdentity)) {
                    $this->logger?->notice('Queued agent run could not be dead-lettered (ownership lost or already terminal); leaving it untouched', ['run' => $runUuid]);

                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $steps, error: $e);
                }
                $this->logger?->warning('Queued agent run failed with a non-retryable error; dead-lettered', ['run' => $runUuid, 'class' => $class->value]);

                return new AgentRunResult(outcome: AgentRunOutcome::FAILED, runUuid: $runUuid, steps: $steps, error: $e);
            }

            // Retryable in principle — but is the requeue budget spent? Re-read
            // the row; a null read (-1) forces the fail-closed dead-letter.
            $currentRun = $this->persister->findRun($runUuid);
            $count      = $currentRun instanceof AgentRun ? $currentRun->requeueCount : -1;
            if ($count < 0 || $count >= self::MAX_REQUEUES) {
                if (!$this->persister->settleDeadLettered($handle, $e, AgentRunTerminationReason::RETRIES_EXHAUSTED, $workerIdentity)) {
                    $this->logger?->notice('Queued agent run could not be dead-lettered (ownership lost or already terminal); leaving it untouched', ['run' => $runUuid]);

                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $steps, error: $e);
                }
                $this->logger?->warning('Queued agent run exhausted its retry budget; dead-lettered', ['run' => $runUuid, 'requeueCount' => $count]);

                return new AgentRunResult(outcome: AgentRunOutcome::FAILED, runUuid: $runUuid, steps: $steps, error: $e);
            }

            // Requeue under an ownership guard. false => this worker no longer
            // owns the run (the reaper reclaimed it, another worker holds it, or
            // a concurrent cancel won). Do NOT settle: the row belongs to its
            // current owner and settling it would destroy that owner's state.
            if (!$this->persister->requeue($handle, $workerIdentity)) {
                $this->logger?->notice('Queued agent run could not be requeued (ownership lost or already terminal); leaving it untouched', ['run' => $runUuid]);

                return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $steps, error: $e);
            }

            // The row is QUEUED again: wake a worker for the retry, backing off.
            // A dispatch failure would strand the QUEUED row (async transport),
            // so the outer catch dead-letters it (finishRun's guard covers
            // QUEUED). The sync transport ignores the delay and re-executes
            // in-process, bounded by MAX_REQUEUES.
            $this->dispatchRequeue($handle->uuid, $count);

            return new AgentRunResult(outcome: AgentRunOutcome::REQUEUED, runUuid: $runUuid, steps: $steps, error: $e);
        } catch (Throwable $internal) {
            $this->logger?->error('Queued agent run recovery failed; dead-lettering', ['run' => $runUuid, 'exception' => $internal]);
            try {
                $this->persister->settleDeadLettered($handle, $e, AgentRunTerminationReason::RETRIES_EXHAUSTED, $workerIdentity);
            } catch (Throwable) {
                // The persister is itself fail-soft; nothing more can be done.
            }

            return new AgentRunResult(outcome: AgentRunOutcome::FAILED, runUuid: $runUuid, steps: $steps, error: $e);
        }
    }

    /**
     * Dispatch a delayed wake-up for a requeued run (ADR-104). Throws when no
     * bus is wired — unreachable in practice (a queued run only exists when
     * enqueue() dispatched, which requires the bus) but surfaced so the caller
     * dead-letters rather than stranding a QUEUED row.
     */
    private function dispatchRequeue(string $uuid, int $priorRequeueCount): void
    {
        if ($this->messageBus === null) {
            throw RunEnqueueFailedException::forRun($uuid);
        }

        // 2 ** $n widens to int|float under static analysis; the cap keeps the
        // result well inside int range, so the cast is exact, not lossy.
        $delayMs = (int)min(self::REQUEUE_BACKOFF_MS * (2 ** $priorRequeueCount), self::REQUEUE_BACKOFF_CAP_MS);
        $this->messageBus->dispatch(new AgentRunQueuedMessage($uuid), [new DelayStamp($delayMs)]);
    }

    /**
     * The shared execution path behind {@see run()} and {@see runQueued()}:
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
    private function executeRequest(
        AgentRunRequest $request,
        ?AgentRunHandle $handle,
        ?Closure $onStep,
        ?string $leaseOwner = null,
        ?Closure $recover = null,
    ): AgentRunResult {
        $maxIterations = $request->maxIterations !== null
            ? min($request->maxIterations, self::MAX_ITERATIONS)
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
     * Serialise a request for the queued row (ADR-102). Entities travel as
     * uids (the rehydrator re-loads them — the same identity-over-snapshot
     * choice approve() makes for the configuration); messages and options use
     * their established array forms (the SuspendedRunState precedent).
     *
     * @return array<string, mixed>
     */
    private function dehydrateRequest(AgentRunRequest $request): array
    {
        $augmentation = $request->augmentation;

        return [
            'messages'         => array_map(
                static fn(ChatMessage|array $m): array => $m instanceof ChatMessage ? $m->toArray() : $m,
                $request->messages,
            ),
            // The FULL initiating actor (ADR-083), not just its backend-user id:
            // a worker rehydrating this run must authorise with the identity that
            // enqueued the work — admin flag, groups, service account — rather
            // than the worker's absent ambient BE user.
            'actor'            => $request->actor->toArray(),
            'allowedToolNames' => $request->allowedToolNames,
            'options'          => $request->options?->toArray(),
            // ToolOptions::toArray() deliberately excludes the budget fields and
            // the idempotency key (they are not provider API fields), but a
            // queued run's budget PRE-FLIGHT has not happened yet — unlike the
            // ADR-084 resume case that exclusion was designed for. Carry them
            // out-of-band so run() and enqueue()+runQueued() of the same
            // request hit the identical budget gate and dedup.
            'plannedCost'      => $request->options?->getPlannedCost(),
            'idempotencyKey'   => $request->options?->getIdempotencyKey(),
            'maxIterations'    => $request->maxIterations,
            'captureRaw'       => $request->captureRaw,
            // null stays null: a non-null augmentation makes the loop bake the
            // effective system prompt into the transcript (ADR-060 assembly),
            // which a null-augmentation run() would not do — losing the
            // distinction would silently change the prompt composition.
            'augmentation'     => $augmentation === null ? null : [
                'forcedSkillUids'   => array_values(array_filter(array_map(
                    static fn(Skill $skill): int => $skill->getUid() ?? 0,
                    $augmentation->forcedSkills,
                ))),
                'forcedSnippetUids' => array_values(array_filter(array_map(
                    static fn(PromptSnippet $snippet): int => $snippet->getUid() ?? 0,
                    $augmentation->forcedSnippets,
                ))),
                'dryRun'            => $augmentation->dryRun,
            ],
        ];
    }

    /**
     * Rebuild the request from a claimed queued row (ADR-102). The
     * configuration and the forced skills/snippets are re-loaded by uid — a
     * configuration deleted while the run was queued fails the run, and a
     * skill/snippet deleted meanwhile is simply no longer forced (the same
     * live-resolution semantics the interactive path has).
     */
    private function rehydrateRequest(AgentRun $run): AgentRunRequest
    {
        // The same typed absence approve() reports — here it is caught by
        // runQueued()'s rehydration guard, which settles the run FAILED with a
        // meaningful error class instead of letting it escape.
        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($configuration === null) {
            throw RunConfigurationGoneException::forRun($run->uuid);
        }

        $data = json_decode($run->queuedRequest ?? '', true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('The stored request of queued run %s could not be decoded', $run->uuid), 2826462004);
        }

        $messages = [];
        foreach (is_array($data['messages'] ?? null) ? $data['messages'] : [] as $message) {
            if (is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        $allowed = null;
        if (is_array($data['allowedToolNames'] ?? null)) {
            $allowed = array_values(array_filter($data['allowedToolNames'], is_string(...)));
        }

        $options = null;
        if (is_array($data['options'] ?? null)) {
            /** @var array<string, mixed> $optionsData */
            $optionsData = $data['options'];
            // The initiator on the run row attributes the budget pre-flight,
            // exactly as the interactive path does.
            $options = ToolOptions::fromArray($optionsData, $run->beUser !== 0 ? $run->beUser : null);
            // Re-inject the out-of-band budget/idempotency fields (see
            // dehydrateRequest()): a queued run's budget pre-flight must be as
            // strict as the direct path's, and its provider calls as
            // deduplicatable.
            $plannedCost = $data['plannedCost'] ?? null;
            if (is_float($plannedCost) || is_int($plannedCost)) {
                $options = $options->withPlannedCost((float)$plannedCost);
            }
            $idempotencyKey = $data['idempotencyKey'] ?? null;
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $options = $options->withIdempotencyKey($idempotencyKey);
            }
        }

        $augmentation = null;
        if (is_array($data['augmentation'] ?? null)) {
            $augmentationData = $data['augmentation'];
            $augmentation     = new RunAugmentation(
                forcedSkills: $this->skillsByUids($this->uidList($augmentationData['forcedSkillUids'] ?? null)),
                forcedSnippets: $this->snippetsByUids($this->uidList($augmentationData['forcedSnippetUids'] ?? null)),
                dryRun: ($augmentationData['dryRun'] ?? false) === true,
            );
        }

        // Restore the full initiating actor from the serialised request. A row
        // queued before actors were persisted has no 'actor' key: fall back to
        // the stored be_user id (the same single-int identity the pre-actor
        // worker had), so an in-flight upgrade never loses or invents privilege.
        $actorData = $data['actor'] ?? null;
        if (is_array($actorData)) {
            /** @var array<string, mixed> $actorData a serialised actor is a JSON object (string keys) */
            $actor = AiActorContext::fromArray($actorData);
        } else {
            $actor = AiActorContext::backendUser($run->beUser);
        }

        return new AgentRunRequest(
            configuration: $configuration,
            messages: $messages,
            actor: $actor,
            allowedToolNames: $allowed,
            options: $options,
            maxIterations: is_int($data['maxIterations'] ?? null) ? $data['maxIterations'] : null,
            augmentation: $augmentation,
            captureRaw: ($data['captureRaw'] ?? false) === true,
        );
    }

    /**
     * @return list<int>
     */
    private function uidList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $uids = [];
        foreach ($value as $uid) {
            if (is_int($uid) && $uid > 0) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * Forced skills by uid, preserving order. Resolved without the enabled
     * filter — forcing a skill overrides its global toggle, the same semantics
     * the playground's force-inject control has.
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    private function skillsByUids(array $uids): array
    {
        if ($uids === [] || $this->skillRepository === null) {
            return [];
        }

        $byUid = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->getUid() !== null) {
                $byUid[$skill->getUid()] = $skill;
            }
        }

        $skills = [];
        foreach ($uids as $uid) {
            if (isset($byUid[$uid])) {
                $skills[] = $byUid[$uid];
            }
        }

        return $skills;
    }

    /**
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    private function snippetsByUids(array $uids): array
    {
        if ($uids === [] || $this->promptSnippetRepository === null) {
            return [];
        }

        return $this->promptSnippetRepository->findByUids($uids);
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

        $trace = $this->trace($handle, $onStep, false);
        // Tools resume under the RUN OWNER's identity (ADR-083), not whoever is
        // approving: the owner is who the queued work acts for. Reconstructed
        // from the run row's initiating uid — the same fallback rehydrateRequest()
        // uses — and resolved to a live user, so authorization is identical to
        // the original synchronous/worker execution.
        $context = $this->toolContext(AiActorContext::backendUser($run->beUser));

        return $this->execute(
            $handle,
            $trace,
            fn(): ToolLoopResult => $this->toolLoop->resume(
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

        $trace = $this->trace($handle, $onStep, false);
        // Tools resume under the RUN OWNER's identity (ADR-083), not the
        // submitter — same rule as approve().
        $context = $this->toolContext(AiActorContext::backendUser($run->beUser));

        return $this->execute(
            $handle,
            $trace,
            fn(): ToolLoopResult => $this->toolLoop->resumeWithInput(
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
     * For a queued run $leaseOwner is this worker's identity (ADR-104): after
     * the cancellation check, the lease is renewed under an ownership guard
     * BEFORE the step is persisted. If the renewal affects no row the worker no
     * longer owns the run — the reaper reclaimed it and another worker may hold
     * it now — so it stops WITHOUT writing the step, which would otherwise
     * collide with the new owner's stream. Interactive runs pass null and never
     * renew (they hold no lease).
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    private function trace(?AgentRunHandle $handle, ?Closure $onStep, bool $captureRaw, ?string $leaseOwner = null): RunTrace
    {
        if ($handle === null && $onStep === null) {
            return new RunTrace(captureRaw: $captureRaw);
        }

        return new RunTrace(
            captureRaw: $captureRaw,
            onRecord: function (RunStep $step) use ($handle, $onStep, $leaseOwner): void {
                // The live observer sees the step FIRST (emit-before-persist),
                // so a step is shown even when its persistence or the checks
                // below abort the loop.
                if ($onStep !== null) {
                    $onStep($step);
                }
                if ($handle === null) {
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
                // whose sequence collides with the new owner's stream.
                if ($leaseOwner !== null
                    && !$this->persister->renewLease($handle, $leaseOwner, time() + self::LEASE_SECONDS)
                ) {
                    throw RunLeaseLostException::forRun($handle->uuid);
                }

                $this->recordStepFailClosedForWrites($handle, $step);
            },
        );
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
        if ($step->kind !== RunStep::KIND_TOOL || $this->toolEffectResolver === null) {
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
        $runUuid = $handle !== null ? $handle->uuid : '';
        $settled = false;

        try {
            $result = $loopCall();

            if ($handle !== null) {
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

            if ($handle !== null && $this->persister->suspendForInput($handle, $input->state)) {
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
            if ($handle !== null) {
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
            if ($handle !== null) {
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
            if ($recover !== null) {
                $recovery = $recover($e, $trace->getSteps());
                if ($recovery instanceof AgentRunResult) {
                    $settled = true;

                    return $recovery;
                }
            }

            if ($handle !== null) {
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
            if ($handle !== null && !$settled) {
                // Ownership-guarded so this safety-net settle cannot clobber a
                // queued run the reaper reclaimed to another worker.
                $this->persister->settleFailed($handle, new RuntimeException('Agent run did not complete'), $ownerGuard);
            }
        }
    }
}
