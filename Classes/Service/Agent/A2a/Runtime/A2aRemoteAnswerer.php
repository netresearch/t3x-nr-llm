<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\RemoteQuestionAnswererInterface;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * Sends the initiator's answer to the remote task a delegation is bound to
 * (spec 060, ADR-311).
 *
 * - The task comes from the checkpoint and its journal row only: the row must
 *   belong to the run and the checkpoint's owner and name the same remote
 *   task. Nothing the remote said later and nothing the person typed chooses
 *   the task.
 * - The answer is one text part through the shared secret redactor, sent with
 *   the bound task and context id under the caller's message id, through the
 *   journal as an intent that continues the first one. A send whose intent
 *   already exists is not repeated: an accepted one is read with GetTask, an
 *   unsettled or unknown one is an unknown outcome.
 * - The record must still be usable now; otherwise nothing is sent.
 * - A remote that answers about another task is not followed (the client
 *   settles that unknown); a settled task is the call's result; otherwise the
 *   run waits again on the same task, with a fresh wait from now, and push is
 *   registered again when it is on.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRemoteAnswerer implements RemoteQuestionAnswererInterface
{
    use SecretShapeRedactorTrait;

    public function __construct(
        private A2aSendJournal $journal,
        private A2aRemoteAgentRepository $agents,
        private A2aClient $client,
        private ?A2aPushRegistrar $push = null,
    ) {}

    public function answer(DependencyCheckpoint $checkpoint, string $answer, string $messageId, int $runUid): ToolResult|DependencyCheckpoint
    {
        $intent = $this->journal->findForRun($runUid, $checkpoint->journalUid);
        if (!$intent instanceof A2aSendIntent || $intent->ownerUid !== $checkpoint->ownerUid || $intent->state !== A2aSendState::ACCEPTED
            || $intent->agentUid !== $checkpoint->agentUid || !hash_equals($checkpoint->remoteTaskId, $intent->remoteTaskId)) {
            return A2aToolResults::unavailable('the remote task this answer belongs to cannot be found');
        }

        $agent = $this->usableAgent($checkpoint->agentUid);
        if (is_string($agent)) {
            return A2aToolResults::unavailable($agent);
        }

        $text = trim($answer);
        if ($text === '') {
            return A2aToolResults::unavailable('the answer is empty');
        }

        try {
            $outcome = $this->client->sendMessage(
                $agent,
                $intent->ownerUid,
                new A2aMessage($messageId, A2aRole::USER, [A2aPart::text($this->redactSecretShapes($text))], $intent->remoteContextId, $intent->remoteTaskId),
                A2aSendMessageConfiguration::returningImmediately(),
                $runUid,
                $intent->uid,
            );
        } catch (A2aException $e) {
            return A2aToolResults::unavailable($e->getMessage());
        }

        $sent = $outcome->intent;

        return match ($sent->state) {
            A2aSendState::ACCEPTED => $this->continued($agent, $intent, $outcome->result->task ?? $this->taskOnce($agent, $intent), $checkpoint),
            A2aSendState::ANSWERED => $outcome->result?->message instanceof A2aMessage
                ? A2aToolResults::message($agent->getTitle(), $outcome->result->message)
                : A2aToolResults::unavailable('the answer could not be read'),
            A2aSendState::REFUSED => A2aToolResults::refused($agent->getTitle(), $sent),
            A2aSendState::OUTCOME_UNKNOWN,
            A2aSendState::INTENDED => A2aToolResults::outcomeUnknown($agent->getTitle(), $sent),
        };
    }

    /**
     * The remote task after the answer: a terminal one is the result; any
     * other state waits on the same task again.
     */
    private function continued(A2aRemoteAgent $agent, A2aSendIntent $intent, ?A2aTask $task, DependencyCheckpoint $checkpoint): ToolResult|DependencyCheckpoint
    {
        if (!$task instanceof A2aTask || $task->id !== $intent->remoteTaskId) {
            return A2aToolResults::unavailable('the remote task could not be read after the answer');
        }

        // The handle of the task is the first intent: GetTask records its
        // state there, and so does this, so the sweep of questions whose run
        // ended (spec 060 RQ9) does not see the question that was answered.
        $this->journal->recordRemoteState($intent, $task->status->rawState);

        if ($task->status->state instanceof A2aTaskState && $task->status->state->isTerminal()) {
            return A2aToolResults::task($agent->getTitle(), $task);
        }

        $now = time();
        $this->push?->register($agent, $intent, $now);
        $totals = $this->journal->runTotals($intent->runUid, $intent->agentUid);

        return new DependencyCheckpoint(
            DependencyCheckpoint::KIND_A2A_TASK,
            $checkpoint->toolCallId,
            $checkpoint->toolName,
            $intent->uid,
            $intent->agentUid,
            $intent->remoteTaskId,
            $intent->ownerUid,
            $checkpoint->workspaceId,
            $now,
            // A fresh wait: the time the initiator took to answer is not the
            // remote's (spec 060 RQ6).
            $now + max(1, $agent->getMaxWaitSeconds()),
            $totals['delegations'],
            $totals['transferredBytes'],
        );
    }

    /**
     * The record as it is now, or the reason it cannot be used.
     */
    private function usableAgent(int $agentUid): A2aRemoteAgent|string
    {
        $trust = $this->agents->currentTrust($agentUid);
        if ($trust === null || !$trust['enabled'] || $trust['dataClass'] === null) {
            return 'the record is gone, disabled or has no data class';
        }

        if ($trust['card']['cardChanged']) {
            return 'the card changed and waits for an administrator to confirm it';
        }

        $agent = $this->agents->findByUid($agentUid);
        if (!$agent instanceof A2aRemoteAgent) {
            return 'the record is gone';
        }

        return $agent->useRefusal() ?? $agent;
    }

    private function taskOnce(A2aRemoteAgent $agent, A2aSendIntent $intent): ?A2aTask
    {
        try {
            return $this->client->getTask($agent, $intent->ownerUid, $intent->remoteTaskId);
        } catch (A2aException) {
            return null;
        }
    }
}
