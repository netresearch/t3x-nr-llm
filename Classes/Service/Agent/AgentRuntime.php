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
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
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
     * Written at claim time; the stale-run reaper epic acts on expiry —
     * until then the lease is diagnostic.
     */
    public const LEASE_SECONDS = 900;

    public function __construct(
        private ToolLoopServiceInterface $toolLoop,
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurationRepository,
        private ?LoggerInterface $logger = null,
        private ?MessageBusInterface $messageBus = null,
        private ?SkillRepository $skillRepository = null,
        private ?PromptSnippetRepository $promptSnippetRepository = null,
    ) {}

    public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult
    {
        $handle = $this->persister->begin($request->configuration, $request->beUserUid);

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
        $handle = $this->persister->enqueue($request->configuration, $request->beUserUid, $requestJson);
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
        if (!$this->persister->claimQueued($run, $this->workerIdentity(), time() + self::LEASE_SECONDS)) {
            return null;
        }

        // The claim is won: from here every failure settles the run rather
        // than leaving it stuck RUNNING.
        $handle = new AgentRunHandle($run->uid, $run->uuid);

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

        return $this->executeRequest($request, $handle, $onStep);
    }

    /**
     * The shared execution path behind {@see run()} and {@see runQueued()}:
     * clamp the round cap, build the trace, drive the ladder.
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    private function executeRequest(AgentRunRequest $request, ?AgentRunHandle $handle, ?Closure $onStep): AgentRunResult
    {
        $maxIterations = $request->maxIterations !== null
            ? min($request->maxIterations, self::MAX_ITERATIONS)
            : null;

        $trace = $this->trace($handle, $onStep, $request->captureRaw);

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

        return new AgentRunRequest(
            configuration: $configuration,
            messages: $messages,
            allowedToolNames: $allowed,
            options: $options,
            maxIterations: is_int($data['maxIterations'] ?? null) ? $data['maxIterations'] : null,
            augmentation: $augmentation,
            captureRaw: ($data['captureRaw'] ?? false) === true,
            beUserUid: $run->beUser,
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

        // Filtered in SQL — a poller pages without re-hydrating the history.
        return $this->persister->findEvents($run->uid, $afterSequence);
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
