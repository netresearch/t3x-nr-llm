<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * What becomes of a queued run that failed (ADR-104): retry it, or stop.
 *
 * The decision has four inputs — whether the error class is retryable at all,
 * whether a non-idempotent write was fenced in flight, how much of the requeue
 * budget is left, and whether this worker still owns the row — and each of them
 * can independently force a dead-letter. Keeping them in one place is what
 * makes the ordering between them reviewable.
 *
 * Every path settles the row itself, so the caller's ladder never falls through
 * to a generic failure. The class is fail-soft by contract: a failure of the
 * recovery machinery dead-letters the run rather than escaping into a caller
 * that, past the claim, does not throw.
 *
 * The retry budget and backoff remain {@see AgentRuntime}'s published
 * constants; what moved here is the decision that reads them.
 */
final readonly class QueuedRunFailureRecovery
{
    public function __construct(
        private AgentRunPersister $persister,
        private ?LoggerInterface $logger = null,
        private ?MessageBusInterface $messageBus = null,
    ) {}

    /**
     * Decide the fate of a FAILED queued run (ADR-104), invoked from the ladder
     * BEFORE the default settleFailed. Returns the outcome that replaces the
     * generic FAILED — for a queued run every branch settles the row itself, so
     * the ladder never falls through to PROVIDER_FAILED. Fully fail-soft: it
     * must never throw — {@see AgentRuntime::runQueued()} does not throw once
     * the claim is won — so a failure of the recovery machinery itself
     * dead-letters the run.
     *
     * @param list<RunStep> $steps
     */
    public function recover(AgentRunHandle $handle, string $runUuid, string $workerIdentity, Throwable $e, array $steps): AgentRunResult
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

            // A non-idempotent write was in flight when this failed (ADR-111): its
            // side effect may already have landed, so a retry could double it.
            // Dead-letter regardless of the retry budget — a retryable error class
            // does not make a repeated write safe.
            if ($currentRun instanceof AgentRun && !AgentRuntime::mayRetryAfterFence($currentRun->pendingEffect)) {
                if (!$this->persister->settleDeadLettered($handle, $e, AgentRunTerminationReason::NOT_RETRYABLE, $workerIdentity)) {
                    return new AgentRunResult(outcome: AgentRunOutcome::LEASE_LOST, runUuid: $runUuid, steps: $steps, error: $e);
                }
                $this->logger?->warning('Queued agent run failed mid non-idempotent write; dead-lettered instead of retried (ADR-111)', ['run' => $runUuid]);

                return new AgentRunResult(outcome: AgentRunOutcome::FAILED, runUuid: $runUuid, steps: $steps, error: $e);
            }

            $count = $currentRun instanceof AgentRun ? $currentRun->requeueCount : -1;
            if ($count < 0 || $count >= AgentRuntime::MAX_REQUEUES) {
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
        $delayMs = (int)min(AgentRuntime::REQUEUE_BACKOFF_MS * (2 ** $priorRequeueCount), AgentRuntime::REQUEUE_BACKOFF_CAP_MS);
        $this->messageBus->dispatch(new AgentRunQueuedMessage($uuid), [new DelayStamp($delayMs)]);
    }
}
