<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\DependencyCancellerInterface;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cancels a run waiting for a remote A2A task and that task (spec 051
 * A2A-R12).
 *
 * The run moves first, in the guarded WAITING_FOR_DEPENDENCY -> CANCELLED
 * transition, so a cancel and the poller's resume claim cannot both win; only
 * the winner sends `CancelTask`, once, with no retry beyond the call's own
 * timeout. The run stays CANCELLED whatever the remote answers, and the answer
 * goes into the journal. `CancelTask` runs as the intent's owner (A2A-C7),
 * whoever pressed cancel, and inside the worker's vault scope on the command
 * line.
 *
 * The checkpoint is read BEFORE the transition: settling clears the sealed
 * state that holds it. A run whose checkpoint cannot be read is still
 * cancelled, but no CancelTask is sent and the journal records no outcome: the
 * checkpoint names the send row, and a run can hold several, so there is no
 * row to pick without it. The remote task then runs on.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDependencyCanceller implements DependencyCancellerInterface
{
    use ErrorMessageSanitizerTrait;

    public function __construct(
        private AgentRunPersister $persister,
        private A2aSendJournal $journal,
        private A2aRemoteAgentRepository $agents,
        private A2aClient $client,
        private WorkerVaultScope $vaultScope,
        private ?LoggerInterface $logger = null,
    ) {}

    public function cancelWaiting(AgentRun $run): bool
    {
        $checkpoint = $this->checkpointOf($run);
        if (!$this->persister->cancelWaitingForDependency($run)) {
            return false;
        }

        $intent = $checkpoint instanceof DependencyCheckpoint ? $this->journal->findForRun($run->uid, $checkpoint->journalUid) : null;
        if ($intent instanceof A2aSendIntent && $intent->remoteTaskId !== '') {
            $this->cancelRemote($intent);
        }

        return true;
    }

    /**
     * Send the one CancelTask of an intent and record what came back. Never
     * throws: the local run's fate does not depend on the remote's answer.
     */
    public function cancelRemote(A2aSendIntent $intent): void
    {
        $agent = $this->agents->findByUid($intent->agentUid);
        if (!$agent instanceof A2aRemoteAgent) {
            $this->record($intent, 'NOT_SENT: the remote agent record is gone');

            return;
        }

        $outcome = $this->vaultScope->call(function () use ($agent, $intent): string {
            try {
                return $this->client->cancelTask($agent, $intent->ownerUid, $intent->remoteTaskId)->status->rawState;
            } catch (A2aCallFailedException $e) {
                return 'FAILED: ' . $e->reason;
            } catch (Throwable $e) {
                $this->logger?->warning('CancelTask for a remote A2A task could not be sent', ['intent' => $intent->uid, 'exception' => $e]);

                return 'NOT_SENT: ' . $this->sanitizeErrorMessage($e->getMessage());
            }
        });
        $this->record($intent, $outcome);
    }

    /**
     * The journal write is the last step and must not undo the promise above:
     * a failed write is logged, and the caller goes on.
     */
    private function record(A2aSendIntent $intent, string $outcome): void
    {
        try {
            $this->journal->recordCancelOutcome($intent, $outcome);
        } catch (Throwable $e) {
            $this->logger?->error('The outcome of a CancelTask could not be journalled', ['intent' => $intent->uid, 'outcome' => $outcome, 'exception' => $e]);
        }
    }

    private function checkpointOf(AgentRun $run): ?DependencyCheckpoint
    {
        $decoded = json_decode($run->suspendedState ?? '', true);

        return is_array($decoded) ? DependencyCheckpoint::fromStoredState($decoded) : null;
    }
}
