<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Persists an agent run and its event stream (ADR-081).
 *
 * A thin, fail-soft orchestrator over {@see AgentRunRepositoryInterface}. It is
 * driven from the same {@see RunTrace} `onRecord` hook the playground already
 * uses to stream steps, so persistence is purely additive — the tool loop is
 * untouched and unaware of it.
 *
 * Every method is fail-soft: a persistence error is logged and swallowed so a
 * database hiccup can never break an otherwise-successful run. {@see self::begin()}
 * returns null on failure, which the caller treats as "do not record" — exactly
 * as a null {@see RunTrace} callback would.
 *
 * What a step actually stores is governed by the central privacy level via
 * {@see RunStepPrivacyFilter}: metadata-only by default, so persistence does not
 * quietly turn the event stream into a prompt archive (ADR-064).
 *
 * A run that exhausts its iteration cap and one the budget guard denied are
 * both COMPLETED with `truncated = true` — the loop swallows the denial and
 * returns a normal result — but they are no longer indistinguishable: the
 * result carries an {@see AgentRunTerminationReason} that is stored alongside
 * the status (ADR-092).
 */
final readonly class AgentRunPersister
{
    public function __construct(
        private AgentRunRepositoryInterface $repository,
        private RunStepPrivacyFilter $privacyFilter,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Open a new run in the RUNNING state. Returns a handle to thread back into
     * {@see self::recordStep()} and the settle methods, or null when the run
     * could not be started (the caller then records nothing).
     */
    public function begin(?LlmConfiguration $configuration, int $beUser): ?AgentRunHandle
    {
        try {
            $uuid   = Uuid::v4()->toRfc4122();
            $runUid = $this->repository->startRun(
                $uuid,
                $configuration?->getUid() ?? 0,
                $configuration?->getIdentifier() ?? '',
                $beUser,
            );

            return new AgentRunHandle($runUid, $uuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be started; the run will not be persisted', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Open a new run in the QUEUED state, carrying its serialised request for a
     * worker to claim and execute (ADR-102). Returns null when the row could
     * not be stored — the caller must fail closed: unlike a live run, a queued
     * run without a persisted row simply does not exist.
     */
    public function enqueue(?LlmConfiguration $configuration, int $beUser, string $requestJson): ?AgentRunHandle
    {
        try {
            $uuid   = Uuid::v4()->toRfc4122();
            $runUid = $this->repository->enqueueRun(
                $uuid,
                $configuration?->getUid() ?? 0,
                $configuration?->getIdentifier() ?? '',
                $beUser,
                $requestJson,
            );

            return new AgentRunHandle($runUid, $uuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be enqueued', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Atomically claim a queued run for execution (ADR-102). Fail-closed like
     * {@see self::claimResume()}: false — refusing the claim — when it is lost
     * to another worker, the run was cancelled while queued, or the store
     * errors, so a queued run is never executed twice.
     */
    public function claimQueued(AgentRun $run, string $claimedBy, int $leaseExpires): bool
    {
        try {
            return $this->repository->claimQueued($run->uid, $claimedBy, $leaseExpires);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be claimed for execution', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Extend the lease on a running run this worker owns (ADR-104 heartbeat).
     * Fail-closed, unlike most methods here: a store error returns false — the
     * worker treats it as a lost lease and stops. Better a run stalls and is
     * reaped later than a worker keeps executing on a lease it can no longer
     * prove it holds, risking a double-execution once the reaper reclaims it.
     */
    public function renewLease(AgentRunHandle $handle, string $claimedBy, int $leaseExpires): bool
    {
        try {
            return $this->repository->renewLease($handle->runUid, $claimedBy, $leaseExpires);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun lease could not be renewed; the worker will treat it as lost', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Put a running run this worker owns back on the queue for a retry (ADR-104).
     * Ownership-guarded in the repository; false when the worker no longer owned
     * the run (reclaimed/cancelled) or the store errored — the caller must then
     * NOT settle it as failed, since the row may belong to another worker now.
     */
    public function requeue(AgentRunHandle $handle, string $claimedBy): bool
    {
        try {
            return $this->repository->requeue($handle->runUid, $claimedBy);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be requeued for retry', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Persist one recorded step as the next event in the run's stream.
     */
    public function recordStep(AgentRunHandle $handle, RunStep $step): void
    {
        try {
            // The persisted copy follows the central privacy level; the live
            // playground stream renders the unfiltered step from memory.
            $payload = json_encode(
                $this->privacyFilter->filter($step->toArray()),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
            $this->repository->recordEvent(
                $handle->runUid,
                $handle->sequence,
                $step->kind,
                $step->round,
                $step->durationMs,
                $payload,
            );
            ++$handle->sequence;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun step could not be persisted', ['exception' => $exception]);
        }
    }

    /**
     * Settle a run that finished normally. The status is always COMPLETED; the
     * result carries whether the loop was cut short and — since ADR-092 — why:
     * an iteration cap and an exhausted budget both truncate and are otherwise
     * indistinguishable in the stored row.
     */
    public function settleCompleted(AgentRunHandle $handle, ToolLoopResult $result, ?string $ownedBy = null): bool
    {
        return $this->finish(
            $handle,
            AgentRunStatus::COMPLETED,
            $result->iterations,
            $result->truncated,
            $result->usage->promptTokens,
            $result->usage->completionTokens,
            $result->usage->totalTokens,
            $result->usage->estimatedCost ?? 0.0,
            '',
            $result->terminationReason,
            $ownedBy,
        );
    }

    /**
     * Settle a run that ended in an uncaught throwable (a provider failure that
     * exhausted every fallback, or an unexpected error). The exception FQCN is
     * stored; the message is never persisted (it can carry payload fragments).
     */
    public function settleFailed(AgentRunHandle $handle, Throwable $error, ?string $ownedBy = null): bool
    {
        return $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class, AgentRunTerminationReason::PROVIDER_FAILED, $ownedBy);
    }

    /**
     * Settle a run a guardrail stopped (ADR-085 ff.): FAILED, but with the
     * policy reason rather than a provider failure, so a denial is not read as
     * an outage — and an approval that was required but never obtained is not
     * read as a denial.
     */
    public function settlePolicyStopped(AgentRunHandle $handle, Throwable $error, AgentRunTerminationReason $reason, ?string $ownedBy = null): bool
    {
        return $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class, $reason, $ownedBy);
    }

    /**
     * Dead-letter a queued run that will not be retried (ADR-104): FAILED, with
     * a reason that says why retrying stopped — RETRIES_EXHAUSTED (the requeue
     * budget is spent) or NOT_RETRYABLE (the failure class cannot be fixed by
     * retrying). Distinct from {@see self::settleFailed()}, whose PROVIDER_FAILED
     * reason is retryable; a dead-letter terminus must not read as retryable.
     */
    public function settleDeadLettered(AgentRunHandle $handle, Throwable $error, AgentRunTerminationReason $reason, ?string $ownedBy = null): bool
    {
        return $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class, $reason, $ownedBy);
    }

    /**
     * Settle a run an operator cancelled. Distinct from a failure: nothing went
     * wrong, somebody stopped it.
     */
    public function settleCancelled(AgentRunHandle $handle, ?string $ownedBy = null): bool
    {
        return $this->finish($handle, AgentRunStatus::CANCELLED, 0, false, 0, 0, 0, 0.0, '', AgentRunTerminationReason::CANCELLED, $ownedBy);
    }

    /**
     * Cancel a run by uuid — the operator-facing entry point behind
     * `nrllm:agent:cancel`.
     *
     * Cancels a run that is still queued, running or waiting for a decision:
     * the row moves to CANCELLED with its resumable state dropped, so a
     * stranded run stops occupying an approval queue. Returns false when the
     * run is unknown or already terminal; the guarded transition in the
     * repository decides, so two concurrent cancels cannot both win.
     */
    public function cancel(string $uuid): bool
    {
        $run = $this->findRun($uuid);
        if ($run === null) {
            return false;
        }

        try {
            return $this->repository->finishRun(
                $run->uid,
                AgentRunStatus::CANCELLED->value,
                $run->iterations,
                $run->truncated,
                $run->totalPromptTokens,
                $run->totalCompletionTokens,
                $run->totalTokens,
                $run->estimatedCost,
                '',
                AgentRunTerminationReason::CANCELLED->value,
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be cancelled', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Suspend a run for human approval (ADR-084): persist the transcript and
     * pending tool calls and move the run to WAITING_FOR_APPROVAL.
     *
     * Returns false when the state could not be stored. Unlike the other
     * methods here, a caller must NOT ignore that: an approval-gated tool is by
     * definition side-effecting, and telling the client "awaiting approval"
     * when nothing was persisted promises a resume that can never happen. The
     * caller settles the run as failed instead (ADR-092).
     */
    public function suspend(AgentRunHandle $handle, SuspendedRunState $state): bool
    {
        try {
            $stateJson = json_encode($state->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            // Guarded transition (ADR-101): false when the run is no longer
            // RUNNING — a concurrent cancel or settle won the row first, and the
            // suspension must not resurrect it.
            if (!$this->repository->suspendRun($handle->runUid, $stateJson)) {
                $this->logger?->notice('AgentRun was no longer running when its suspension arrived; the suspension was discarded', ['run' => $handle->uuid]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be suspended', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Suspend a run for typed user input (ADR-105): the input sibling of
     * {@see self::suspend()}. Persists the transcript, pending calls and the
     * tool's declared input schema (all inside $state) and moves the run to
     * WAITING_FOR_INPUT. Fail-closed like suspend(): false when the state could
     * not be stored (store error or a concurrent cancel won) — the caller then
     * settles the run failed rather than promising a resume that cannot happen.
     */
    public function suspendForInput(AgentRunHandle $handle, SuspendedRunState $state): bool
    {
        try {
            $stateJson = json_encode($state->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            if (!$this->repository->suspendRunForInput($handle->runUid, $stateJson)) {
                $this->logger?->notice('AgentRun was no longer running when its input suspension arrived; it was discarded', ['run' => $handle->uuid]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be suspended for input', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Load a persisted run by uuid. Exposed on the persister (a service) so a
     * controller can reach a run without depending on the repository directly
     * (the layered-architecture rule). Null when unknown or unavailable.
     */
    public function findRun(string $uuid): ?AgentRun
    {
        try {
            return $this->repository->findByUuid($uuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be loaded', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Runs awaiting a human decision, for the approvals inbox (ADR-084/105).
     * Fail-soft but HONEST: `null` strictly on a store error (so the controller
     * shows a visible load error instead of a silent empty inbox that could hide
     * waiting runs behind a DB hiccup), an empty list when there genuinely are
     * none. Exposed on the persister so the controller does not depend on the
     * repository directly (the layered-architecture rule).
     *
     * @return list<AgentRun>|null null on a load error, else the (possibly empty) list
     */
    public function findAwaitingRuns(int $limit = 100): ?array
    {
        try {
            return $this->repository->findAwaiting($limit);
        } catch (Throwable $exception) {
            $this->logger?->warning('Awaiting agent runs could not be loaded', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * The most recent terminal runs for the inbox's read-only context. Fail-soft
     * and honest like {@see findAwaitingRuns()}: `null` on error, else the list.
     *
     * @return list<AgentRun>|null null on a load error, else the (possibly empty) list
     */
    public function findRecentTerminalRuns(int $limit = 20): ?array
    {
        try {
            return $this->repository->findRecentTerminal($limit);
        } catch (Throwable $exception) {
            $this->logger?->warning('Recent terminal agent runs could not be loaded', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Atomically claim a suspended run before resuming it (ADR-084). Fail-closed:
     * returns false — refusing the resume — if the claim is lost to a concurrent
     * approval or the store errors, so the gated tool is never double-executed.
     */
    public function claimResume(AgentRun $run): bool
    {
        try {
            return $this->repository->claimForResume($run->uid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be claimed for resume', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Atomically claim a WAITING_FOR_INPUT run before resuming it (ADR-105): the
     * input sibling of {@see self::claimResume()}. Fail-closed — false if the
     * claim is lost to a concurrent submission or the store errors — so the
     * pending turn is never executed twice.
     */
    public function claimResumeFromInput(AgentRun $run): bool
    {
        try {
            return $this->repository->claimForResumeFromInput($run->uid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be claimed for input resume', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Rebuild a live handle for an existing (suspended) run so a resume continues
     * its event stream at the right sequence — MAX(sequence) + 1, so a gap in the
     * stored stream can never produce a duplicate.
     *
     * Fail-closed exception to the fail-soft rule (like {@see self::suspend()}):
     * null when the position cannot be determined. Restarting at 0 would insert
     * duplicate sequence numbers and interleave the resumed segment into the
     * middle of the stream — silently corrupting the event order the ADR-101
     * `events()` API is defined by. The caller refuses the resume instead (the
     * run stays suspended, the approval can be retried).
     */
    public function resumeHandle(AgentRun $run): ?AgentRunHandle
    {
        try {
            $handle           = new AgentRunHandle($run->uid, $run->uuid);
            $handle->sequence = $this->repository->maxEventSequence($run->uid) + 1;

            return $handle;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun event position could not be determined; the resume is refused', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Persist an operator's approval decision as the next event in the run's
     * stream (ADR-101): kind {@see AgentEventKind::APPROVAL}, payload
     * ``{approved, decidedBy}``. Best-effort like every event write — a failure
     * is logged, never blocks the decided continuation.
     */
    public function recordApproval(AgentRunHandle $handle, bool $approved, int $decidedByBeUser): void
    {
        try {
            $payload = json_encode(
                ['approved' => $approved, 'decidedBy' => $decidedByBeUser],
                JSON_THROW_ON_ERROR,
            );
            $this->repository->recordEvent(
                $handle->runUid,
                $handle->sequence,
                AgentEventKind::APPROVAL->value,
                0,
                0.0,
                $payload,
            );
            ++$handle->sequence;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun approval decision could not be persisted', ['exception' => $exception]);
        }
    }

    /**
     * Persist a user's input submission as the next event in the run's stream
     * (ADR-105): kind {@see AgentEventKind::INPUT}, payload ``{submittedBy}``
     * ONLY — never the submitted values, which are privacy-filtered content
     * (ADR-064), mirroring {@see self::recordApproval()}'s who/when-not-what
     * rule. Best-effort — a failure is logged, never blocks the continuation.
     */
    public function recordInput(AgentRunHandle $handle, int $submittedByBeUser): void
    {
        try {
            $payload = json_encode(
                ['submittedBy' => $submittedByBeUser],
                JSON_THROW_ON_ERROR,
            );
            $this->repository->recordEvent(
                $handle->runUid,
                $handle->sequence,
                AgentEventKind::INPUT->value,
                0,
                0.0,
                $payload,
            );
            ++$handle->sequence;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun input submission could not be persisted', ['exception' => $exception]);
        }
    }

    /**
     * The persisted event stream of a run, ordered by sequence — the read side
     * of the ADR-101 `events()` API. Only events with sequence > $afterSequence
     * (filtered in SQL, so a poller pages cheaply). Empty on error or for an
     * unknown run.
     *
     * @return list<AgentRunEvent>
     */
    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        try {
            return $this->repository->findEvents($runUid, $afterSequence);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun events could not be loaded', ['exception' => $exception]);

            return [];
        }
    }

    /**
     * Running runs whose lease has expired (ADR-104 reaper). Fail-soft: an empty
     * list on error, so a reaper tick simply does nothing that pass.
     *
     * @return list<AgentRun>
     */
    public function findStaleRunning(int $now, int $limit = 50): array
    {
        try {
            return $this->repository->findStaleRunning($now, $limit);
        } catch (Throwable $exception) {
            $this->logger?->warning('Stale agent runs could not be loaded', ['exception' => $exception]);

            return [];
        }
    }

    /**
     * Reclaim a stale running run onto the queue (ADR-104 reaper). Fail-soft:
     * false on error or when the run was renewed between select and update, so
     * the reaper skips it and no dispatch follows.
     */
    public function requeueStale(AgentRun $run, int $now): bool
    {
        try {
            return $this->repository->requeueStale($run->uid, $now);
        } catch (Throwable $exception) {
            $this->logger?->warning('Stale agent run could not be requeued', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Dead-letter a stale running run whose retry budget is spent (ADR-104
     * reaper): staleness-guarded in the repository so a heartbeat renewal wins.
     * Fail-soft: false on error or when the run was no longer stale.
     */
    public function settleDeadLetteredStale(AgentRun $run, int $now, AgentRunTerminationReason $reason): bool
    {
        try {
            return $this->repository->deadLetterStale($run->uid, $now, $reason->value);
        } catch (Throwable $exception) {
            $this->logger?->warning('Stale agent run could not be dead-lettered', ['exception' => $exception]);

            return false;
        }
    }

    private function finish(
        AgentRunHandle $handle,
        AgentRunStatus $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
        AgentRunTerminationReason $reason,
        ?string $ownedBy = null,
    ): bool {
        try {
            $transitioned = $this->repository->finishRun(
                $handle->runUid,
                $status->value,
                $iterations,
                $truncated,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $estimatedCost,
                $errorClass,
                $reason->value,
                $ownedBy,
            );

            if (!$transitioned) {
                // Not settled by this call: the run was already terminal (a
                // duplicate/late settle — the first, correct outcome was kept),
                // or, on the ownership-guarded queued path, this worker no longer
                // owns it (the reaper reclaimed it to another worker). Either way
                // the caller must not report a terminal outcome it did not write.
                $this->logger?->notice('AgentRun was not settled by this call (already terminal or ownership lost)', [
                    'run'    => $handle->uuid,
                    'status' => $status->value,
                    'reason' => $reason->value,
                ]);
            }

            return $transitioned;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be settled', ['exception' => $exception]);

            return false;
        }
    }
}
