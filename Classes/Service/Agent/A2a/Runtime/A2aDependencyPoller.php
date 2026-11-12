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
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Psr\Log\LoggerInterface;

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
     * @param (Closure(int): int)|null $jitter the jitter added to an interval, 0 to a quarter of it by default; tests pass a fixed one
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
        ?Closure $jitter = null,
    ) {
        $this->jitter = $jitter ?? static fn (int $interval): int => random_int(0, max(0, intdiv($interval, 4)));
    }

    public function tick(int $now, int $limit = 100): A2aPollReport
    {
        $report = new A2aPollReport();
        foreach ($this->resumes->waitingRuns($limit) as [$run, $checkpoint]) {
            if (!$checkpoint instanceof DependencyCheckpoint) {
                $report->failed += $this->resumes->failUnreadable($run, 'The run waits for a dependency but its checkpoint cannot be read') ? 1 : 0;

                continue;
            }

            if ($checkpoint->kind !== DependencyCheckpoint::KIND_A2A_TASK) {
                continue;
            }

            $this->pollOne($run, $checkpoint, $now, $report);
        }

        // The worker's retry of a projection that failed after its commit
        // (A2A-R15): from the committed task view, never by running anything.
        $report->projected = $this->projector?->projectPending($limit) ?? 0;

        return $report;
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
            $result = $this->resume($run, $checkpoint, A2aToolResults::timedOut($title, $checkpoint->remoteTaskId, $checkpoint->deadline - $checkpoint->suspendedAt), function () use ($intent): void {
                $this->canceller->cancelRemote($intent);
            });
            $report->timedOut += $result instanceof AgentRunResult ? 1 : 0;

            return;
        }

        $due = $intent->nextPollAt ?? ($checkpoint->suspendedAt + $this->minimum($agent));
        if ($now < $due) {
            ++$report->notDue;

            return;
        }

        $task = $this->look($agent, $intent);
        if ($task instanceof ToolResult) {
            $report->resumed += $this->resume($run, $checkpoint, $task) instanceof AgentRunResult ? 1 : 0;

            return;
        }

        $state = $task?->status->state;
        if ($task instanceof A2aTask && $state instanceof A2aTaskState && ($state->isTerminal() || $state->isInterrupted())) {
            $report->resumed += $this->resume($run, $checkpoint, A2aToolResults::task($title, $task)) instanceof AgentRunResult ? 1 : 0;

            return;
        }

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
     * @param (Closure(): void)|null $onClaimed
     */
    private function resume(AgentRun $run, DependencyCheckpoint $checkpoint, ToolResult $result, ?Closure $onClaimed = null): ?AgentRunResult
    {
        return $this->vaultScope->call(fn (): ?AgentRunResult => $this->resumes->resume($run, $checkpoint, $result, $onClaimed));
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
