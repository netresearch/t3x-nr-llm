<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aPushRegistrationStore;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aPushDeliverer;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\Agent\RunScopedStateInterface;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * The polling coordinator of the A2A client (spec 051 A2A-R10, ADR-281): one
 * tick looks at every run waiting for a remote A2A task whose poll is due.
 *
 * - The first look is at the record's minimum poll interval after the send;
 *   each look without news doubles the interval up to the record's maximum,
 *   with up to a quarter of jitter added.
 * - A task in a terminal or interrupted state resumes the run with its
 *   result (A2A-R11). A JSON-RPC error from the remote (the task is gone)
 *   resumes it with that error.
 * - A failure at the transport level, a temporary remote error (`-32000`
 *   server error, `-32603` internal error), or a record that cannot be used
 *   for now, is "no news", not a remote failure, until the deadline.
 * - At the deadline the run resumes with a "timed out" result, and the
 *   process that won the run sends `CancelTask` once (A2A-R12).
 *
 * - A push callback the receiver stored (A2A-PUSH8) brings the next look
 *   forward, to the record's minimum interval after the last look, never
 *   closer. The look is GetTask as the owner; the callback body decides
 *   nothing. Each look deletes the callbacks received until it began. The
 *   registration of a delegation closes once the poller claims its run with
 *   the remote's result or at the deadline, before the run continues, so it
 *   is closed even when the run goes on to wait for another delegation. At
 *   the end of each tick the registrations of runs that ended are closed.
 *
 * Every remote call runs as the intent's owner inside the worker's vault scope,
 * and so does the continued run. Polling stays the repair path when push
 * (stage 5b) is in use.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDependencyPoller
{
    /** JSON-RPC errors that say the remote failed for now, not that the task is gone. */
    private const RETRYABLE_ERRORS = [A2aErrorCode::SERVER_ERROR->value, A2aErrorCode::INTERNAL_ERROR->value];

    /** @var Closure(int): int */
    private Closure $jitter;

    /**
     * @param (Closure(int): int)|null          $jitter          the jitter added to an interval, 0 to a quarter of it by default; tests pass a fixed one
     * @param iterable<RunScopedStateInterface> $runScopedStates reset around each resume, as for a queued run
     */
    public function __construct(
        private DependencyResumeCoordinator $resumes,
        private A2aSendJournal $journal,
        private A2aRemoteAgentRepository $agents,
        private A2aClient $client,
        private A2aDependencyCanceller $canceller,
        private WorkerVaultScope $vaultScope,
        private ?A2aTaskProjector $projector = null,
        private ?LoggerInterface $logger = null,
        private ?A2aPushDeliverer $pushes = null,
        private ?A2aPushRegistrationStore $pushInbox = null,
        ?Closure $jitter = null,
        #[AutowireIterator(RunScopedStateInterface::TAG)]
        private iterable $runScopedStates = [],
    ) {
        $this->jitter = $jitter ?? static fn (int $interval): int => random_int(0, max(0, intdiv($interval, 4)));
    }

    public function tick(int $now, int $limit = 100): A2aPollReport
    {
        $report = new A2aPollReport();
        // Pages on past runs that are not due yet, until $limit runs were
        // looked at or none is left: old runs that wait long must not keep
        // newer due ones out of every tick.
        $after     = 0;
        $remaining = $limit;
        do {
            $page = $this->resumes->waitingRuns($limit, $after);
            foreach ($page as [$run, $checkpoint]) {
                $after = $run->uid;
                if ($this->lookAt($run, $checkpoint, $now, $report) && --$remaining === 0) {
                    break 2;
                }
            }
        } while (count($page) === $limit);

        // Runs that wait for their initiator's answer to a remote question
        // keep the delegation's deadline (spec 060 RQ6).
        $afterIntent = 0;
        do {
            $questions = $this->journal->questionsAwaitingAnswer($limit, $afterIntent);
            foreach ($questions as $question) {
                $afterIntent        = $question['intentUid'];
                [$run, $checkpoint] = $this->resumes->questionRun($question['runUuid']) ?? [null, null];
                if ($run instanceof AgentRun && $checkpoint instanceof DependencyCheckpoint && $now >= $checkpoint->deadline) {
                    $this->expire($run, $checkpoint, $report);
                }
            }
        } while (count($questions) === $limit);

        // A remote task whose question nobody answers any more, because its
        // run ended another way, is cancelled once (spec 060 RQ9).
        foreach ($this->journal->questionsOfEndedRuns($limit) as $orphaned) {
            $this->canceller->cancelRemote($orphaned);
        }

        // Push registrations of runs that no longer run or wait, those this
        // tick resumed included, close here (A2A-PUSH8).
        $this->pushInbox?->closeEnded();

        // The worker's retry of a projection that failed after its commit
        // (A2A-R15): from the committed task view, never by running anything.
        $report->projected = $this->projector?->projectPending($limit) ?? 0;

        // The push outbox of the server role (A2A-PUSH3): delivered here, in
        // the worker, never in a request.
        $report->pushed = $this->pushes?->deliverDue($now, $limit) ?? 0;

        return $report;
    }

    /**
     * Whether the run counted against the tick's limit: anything but a run
     * whose poll is not due yet.
     */
    private function lookAt(AgentRun $run, ?DependencyCheckpoint $checkpoint, int $now, A2aPollReport $report): bool
    {
        if (!$checkpoint instanceof DependencyCheckpoint) {
            $report->failed += $this->resumes->failUnreadable($run, 'The run waits for a dependency but its checkpoint cannot be read') ? 1 : 0;

            return true;
        }

        if ($checkpoint->kind !== DependencyCheckpoint::KIND_A2A_TASK) {
            return false;
        }

        $notDue = $report->notDue;
        $this->pollOne($run, $checkpoint, $now, $report);

        return $report->notDue === $notDue;
    }

    private function pollOne(AgentRun $run, DependencyCheckpoint $checkpoint, int $now, A2aPollReport $report): void
    {
        $intent = $this->journal->findForRun($run->uid, $checkpoint->journalUid);
        $agent  = $this->agents->findByUid($checkpoint->agentUid);
        if (!$intent instanceof A2aSendIntent || $intent->ownerUid !== $checkpoint->ownerUid) {
            $report->failed += $this->resumes->failUnreadable($run, 'The journal row of the dependency is gone or names another owner') ? 1 : 0;

            return;
        }

        $title = $agent instanceof A2aRemoteAgent ? $agent->getTitle() : sprintf('#%d', $checkpoint->agentUid);

        if ($now >= $checkpoint->deadline) {
            $result = $this->resume($run, $checkpoint, A2aToolResults::timedOut($title, $checkpoint->remoteTaskId, $checkpoint->deadline - $checkpoint->suspendedAt, $intent->lastRemoteState === A2aTaskState::AUTH_REQUIRED->value), function () use ($intent): void {
                $this->canceller->cancelRemote($intent);
            });
            $report->timedOut += $result instanceof AgentRunResult ? 1 : 0;

            return;
        }

        $due = $intent->nextPollAt ?? ($checkpoint->suspendedAt + $this->minimum($agent));
        if ($this->pushInbox?->hasUnreconciled($intent->uid) === true) {
            // A callback only brings the look forward, never closer than the
            // minimum interval after the last one (A2A-PUSH8).
            $due = min($due, ($this->pushInbox->lastLookAt($intent->uid) ?? $checkpoint->suspendedAt) + $this->minimum($agent));
        }

        if ($now < $due) {
            ++$report->notDue;

            return;
        }

        $task = $this->look($agent, $intent);
        $this->pushInbox?->looked($intent->uid, $now);
        if ($task instanceof ToolResult) {
            $report->resumed += $this->resume($run, $checkpoint, $task) instanceof AgentRunResult ? 1 : 0;

            return;
        }

        $state = $task?->status->state;
        if ($task instanceof A2aTask && $state instanceof A2aTaskState && $state->isTerminal()) {
            $report->resumed += $this->resume($run, $checkpoint, A2aToolResults::task($title, $task)) instanceof AgentRunResult ? 1 : 0;

            return;
        }

        if ($task instanceof A2aTask && $state === A2aTaskState::INPUT_REQUIRED) {
            // The question goes to the run's initiator, never to the model
            // and never answered from here (spec 060 RQ1, RQ3).
            $asked = $this->resumes->askInitiator($run, $checkpoint, A2aToolResults::question($title, $task), function () use ($checkpoint): void {
                $this->closePush($checkpoint->journalUid);
            }, $this->endpointOf($checkpoint->agentUid));
            $report->asked += $asked ? 1 : 0;

            return;
        }

        // Anything else, AUTH_REQUIRED included, is waited out: this
        // installation sends nothing in reply to a request for
        // authentication (spec 060 RQ8).

        $interval = $this->nextInterval($agent, $intent->pollInterval);
        $this->journal->schedulePoll($intent, min($checkpoint->deadline, $now + $interval + ($this->jitter)($interval)), $interval);
        ++$report->waiting;
    }

    /**
     * The remote task, a tool result for a remote error, or null for no news.
     */
    private function look(?A2aRemoteAgent $agent, A2aSendIntent $intent): A2aTask|ToolResult|null
    {
        if (!$agent instanceof A2aRemoteAgent) {
            return null;
        }

        return $this->vaultScope->call(function () use ($agent, $intent): A2aTask|ToolResult|null {
            try {
                return $this->client->getTask($agent, $intent->ownerUid, $intent->remoteTaskId);
            } catch (A2aCallFailedException $e) {
                if (!$e->remoteError instanceof A2aRemoteError) {
                    // Transport or local refusal: no news (A2A-R10).
                    $this->logger?->info('A poll of a remote A2A task brought no news', ['intent' => $intent->uid, 'reason' => $e->reason]);

                    return null;
                }

                if (in_array($e->remoteError->code, self::RETRYABLE_ERRORS, true)) {
                    // The remote failed for now; that says nothing about the
                    // task. No news until the deadline (A2A-R10).
                    $this->logger?->info('A poll of a remote A2A task met a temporary remote error', ['intent' => $intent->uid, 'code' => $e->remoteError->code]);

                    return null;
                }

                return ToolResult::error(sprintf(
                    'Error: [via the remote A2A agent "%s"] The remote agent answered the status request for task %s with an error (%s). There is no result.',
                    $agent->getTitle(),
                    $intent->remoteTaskId,
                    $e->reason,
                ));
            } catch (A2aException) {
                // The record cannot be used for now, or the handle is gone: no news.
                return null;
            }
        });
    }

    /**
     * Once the run is claimed and before it continues, the delegation's push
     * registration closes (A2A-PUSH8), then $onClaimed runs.
     *
     * @param (Closure(): void)|null $onClaimed
     */
    private function resume(AgentRun $run, DependencyCheckpoint $checkpoint, ToolResult $result, ?Closure $onClaimed = null): ?AgentRunResult
    {
        $claimed = function () use ($checkpoint, $onClaimed): void {
            $this->closePush($checkpoint->journalUid);
            if ($onClaimed instanceof Closure) {
                $onClaimed();
            }
        };

        // One tick resumes many runs in one process: what one run memoised
        // must not answer for the next (see AgentRuntime::runQueued()).
        $this->resetRunScopedState();

        try {
            return $this->vaultScope->call(fn (): ?AgentRunResult => $this->resumes->resume($run, $checkpoint, $result, $claimed));
        } finally {
            $this->resetRunScopedState();
        }
    }

    /**
     * A question nobody answered before the delegation's deadline: the run
     * goes on with the timeout result, and the remote task is cancelled once
     * by the process that won the run.
     */
    private function expire(AgentRun $run, DependencyCheckpoint $checkpoint, A2aPollReport $report): void
    {
        $intent = $this->journal->findForRun($run->uid, $checkpoint->journalUid);
        if (!$intent instanceof A2aSendIntent || $intent->ownerUid !== $checkpoint->ownerUid) {
            return;
        }

        $agent  = $this->agents->findByUid($checkpoint->agentUid);
        $title  = $agent instanceof A2aRemoteAgent ? $agent->getTitle() : sprintf('#%d', $checkpoint->agentUid);
        $result = A2aToolResults::timedOut($title, $checkpoint->remoteTaskId, $checkpoint->deadline - $checkpoint->suspendedAt);

        $this->resetRunScopedState();
        try {
            $resumed = $this->vaultScope->call(fn (): ?AgentRunResult => $this->resumes->expireQuestion($run, $checkpoint, $result, function () use ($intent): void {
                $this->canceller->cancelRemote($intent);
            }));
        } finally {
            $this->resetRunScopedState();
        }

        $report->timedOut += $resumed instanceof AgentRunResult ? 1 : 0;
    }

    /**
     * The endpoint the question came from, from the stored card; empty when
     * the record cannot be read, so no answer is ever sent for it.
     */
    private function endpointOf(int $agentUid): string
    {
        $card = $this->agents->currentTrust($agentUid)['card'] ?? null;

        return is_array($card) ? A2aRemoteAgentCard::endpoint($card) : '';
    }

    private function resetRunScopedState(): void
    {
        foreach ($this->runScopedStates as $state) {
            $state->reset();
        }
    }

    /**
     * A failure is logged and does not stop the run; the sweep at the end of
     * a tick removes the registration once the run has ended.
     */
    private function closePush(int $sendUid): void
    {
        try {
            $this->pushInbox?->close($sendUid);
        } catch (Throwable $e) {
            $this->logger?->error('The push registration of a resolved delegation could not be closed', ['intent' => $sendUid, 'exception' => $e]);
        }
    }

    private function nextInterval(?A2aRemoteAgent $agent, int $previous): int
    {
        $minimum = $this->minimum($agent);
        $maximum = max($minimum, $agent?->getMaxPollIntervalSeconds() ?? $minimum);

        return $previous < $minimum ? $minimum : min($maximum, $previous * 2);
    }

    private function minimum(?A2aRemoteAgent $agent): int
    {
        return max(1, $agent?->getMinPollIntervalSeconds() ?? 5);
    }
}
