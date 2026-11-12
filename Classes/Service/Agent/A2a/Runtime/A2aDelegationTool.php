<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentSkill;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\DependencyToolInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyPendingException;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;

/**
 * Delegates a step of a run to one skill of a remote A2A agent (spec 051
 * A2A-R8, A2A-R9; ADR-281).
 *
 * Built by {@see A2aDelegationToolProvider} from the record alone. Like an
 * MCP tool it is inert until an operator enables it on a configuration, it
 * needs an administrator, its data class is the record's (ToolCallPolicy's
 * trust zone, ADR-094), approval follows the record's flag (ADR-084), and it
 * declares NON_IDEMPOTENT_WRITE: a send creates state on the remote, so the
 * call is fenced (ADR-111) and a run reaped mid-send is dead-lettered, never
 * re-executed.
 *
 * Executing it:
 *
 * 1. The record is read again and must be usable; the run must be persisted,
 *    because a checkpoint needs a run; the run's delegations to this agent
 *    must be below the record's limit (A2A-R7, counted in the journal).
 * 2. The message is sent with `return_immediately` true, through the
 *    send-intent journal (A2A-C4).
 * 3. A message answer or a task already in a terminal or interrupted state is
 *    the tool result. A refusal and an unknown outcome are error results; an
 *    unknown outcome is never resent (A2A-C6).
 * 4. An accepted task in any other state suspends the run: the tool throws
 *    {@see ToolDependencyPendingException} with the checkpoint, and the poller
 *    resumes the run when the task settles or the record's maximum wait
 *    passes.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDelegationTool implements DependencyToolInterface, RemoteApprovalInterface, ToolDataClassInterface, ToolEffectInterface
{
    public const NAME_PREFIX = 'a2a_';

    public function __construct(
        private A2aRemoteAgent $agent,
        private A2aAgentSkill $skill,
        private A2aClient $client,
        private A2aSendJournal $journal,
        private A2aRemoteAgentRepository $agents,
    ) {}

    /**
     * The tool name of one skill of one agent: lower-case letters, digits and
     * underscores, at most 64 characters, so every provider accepts it.
     *
     * The readable part (identifier and skill id, folded and cut) can be the
     * same for two pairs: an underscore inside an identifier reads like the
     * separator, case is folded, other characters become `_`. The name
     * therefore always ends in a hash of the exact pair, the record uid and
     * the skill id as the card has it, so no skill of one agent can take the
     * name, and with it the approvals and policies, of another.
     */
    public static function nameFor(int $agentUid, string $agentIdentifier, string $skillId): string
    {
        $readable = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($agentIdentifier) . '_' . strtolower($skillId));

        return self::NAME_PREFIX . substr($readable, 0, 51) . '_' . substr(hash('sha256', $agentUid . "\0" . $skillId), 0, 8);
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::nameFor((int) $this->agent->getUid(), $this->agent->getIdentifier(), $this->skill->id),
            $this->description(),
            [
                'type'       => 'object',
                'properties' => [
                    'message' => [
                        'type'        => 'string',
                        'description' => 'The complete task for the remote agent. It sees nothing of this conversation but this text.',
                    ],
                ],
                'required' => ['message'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $text = is_string($arguments['message'] ?? null) ? trim($arguments['message']) : '';
        if ($text === '') {
            return ToolResult::error('Error: the argument "message" is empty. Nothing was sent.');
        }

        // Read again, past the identity map: the record may have been
        // disabled or reclassified since the tool list was built, and the
        // policy that let this call through asked the current record.
        $trust = $this->agents->currentTrust((int) $this->agent->getUid());
        if ($trust === null || !$trust['enabled'] || $trust['dataClass'] === null) {
            return A2aToolResults::unavailable('the record is gone, disabled or has no data class');
        }

        $agent = $this->agents->findByUid((int) $this->agent->getUid());
        if (!$agent instanceof A2aRemoteAgent) {
            return A2aToolResults::unavailable('the record is gone');
        }

        $refusal = $this->cardRefusal($trust['card'], $agent) ?? $agent->useRefusal();
        if ($refusal !== null) {
            return A2aToolResults::unavailable($refusal);
        }

        $owner = $context->actor->backendUserUid;
        $run   = $context->run;
        if ($owner < 1 || !$run instanceof AgentRunReference || $run->uid < 1) {
            return A2aToolResults::unavailable('a delegation needs a stored run with a backend user to wait in');
        }

        $agentUid = (int) $agent->getUid();
        $before   = $this->journal->runTotals($run->uid, $agentUid);
        if ($before['delegations'] >= $agent->getMaxDelegationsPerRun()) {
            return ToolResult::error(sprintf(
                'Error: this run has made its %d delegation(s) to "%s", the limit of the record. Nothing was sent.',
                $agent->getMaxDelegationsPerRun(),
                $agent->getTitle(),
            ));
        }

        try {
            $outcome = $this->client->sendMessage(
                $agent,
                $owner,
                A2aMessage::userText(A2aClient::newMessageId(), $text),
                A2aSendMessageConfiguration::returningImmediately(),
                $run->uid,
            );
        } catch (A2aException $e) {
            return A2aToolResults::unavailable($e->getMessage());
        }

        $intent = $outcome->intent;

        return match ($intent->state) {
            A2aSendState::ANSWERED => $outcome->result?->message instanceof A2aMessage
                ? A2aToolResults::message($agent->getTitle(), $outcome->result->message)
                : A2aToolResults::unavailable('the answer could not be read'),
            A2aSendState::ACCEPTED        => $this->accepted($agent, $intent->uid, $outcome->result?->task, $owner, $context, $run),
            A2aSendState::REFUSED         => A2aToolResults::refused($agent->getTitle(), $intent),
            A2aSendState::OUTCOME_UNKNOWN => A2aToolResults::outcomeUnknown($agent->getTitle(), $intent),
            A2aSendState::INTENDED        => A2aToolResults::unavailable('the intent was not settled'),
        };
    }

    public function isEnabledByDefault(): bool
    {
        return false;
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function getGroup(): string
    {
        return 'a2a_' . $this->agent->getIdentifier();
    }

    /**
     * The record's data class as it is now, not as it was when the tool list
     * was built: the registry, and with it this object, lives as long as a
     * worker process. A record whose class was removed answers the strictest
     * class, so the policy does not offer it to a looser zone; execute()
     * refuses it anyway.
     */
    public function getDataClass(): ToolDataClass
    {
        $trust = $this->agents->currentTrust((int) $this->agent->getUid());

        return $trust === null ? $this->strictest() : ($trust['dataClass'] ?? $this->strictest());
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * Read now, for the same reason as {@see self::getDataClass()}; a record
     * that cannot be read requires approval (fail-closed).
     */
    public function requiresApproval(): bool
    {
        return $this->agents->currentTrust((int) $this->agent->getUid())['requiresApproval'] ?? true;
    }

    /**
     * The card as stored now against the record object the send would use:
     * a card waiting for confirmation, or one an import changed since the
     * object was read, sends nothing.
     *
     * @param array{cardUrl: string, interfaceUrl: string, interfaceTenant: string, cardChanged: bool, confirmedInterface: string} $card
     */
    private function cardRefusal(array $card, A2aRemoteAgent $agent): ?string
    {
        if ($card['cardChanged']) {
            return 'the card changed and waits for an administrator to confirm it';
        }

        $read = [$agent->getCardUrl(), $agent->getInterfaceUrl(), $agent->getInterfaceTenant(), $agent->getConfirmedInterface()];
        if ($read !== [$card['cardUrl'], $card['interfaceUrl'], $card['interfaceTenant'], $card['confirmedInterface']]) {
            return 'the card changed since this worker read the record; nothing was sent';
        }

        return null;
    }

    private function strictest(): ToolDataClass
    {
        return array_reduce(ToolDataClass::cases(), ToolDataClass::strictest(...), ToolDataClass::PUBLIC_CONTENT);
    }

    /**
     * @throws ToolDependencyPendingException when the task has not settled
     */
    private function accepted(A2aRemoteAgent $agent, int $journalUid, ?A2aTask $task, int $owner, ToolExecutionContext $context, AgentRunReference $run): ToolResult
    {
        if (!$task instanceof A2aTask) {
            return A2aToolResults::unavailable('the accepted task could not be read');
        }

        $state = $task->status->state;
        if ($state instanceof A2aTaskState && ($state->isTerminal() || $state->isInterrupted())) {
            return A2aToolResults::task($agent->getTitle(), $task);
        }

        $now    = time();
        $totals = $this->journal->runTotals($run->uid, (int) $agent->getUid());

        throw new ToolDependencyPendingException(
            new DependencyCheckpoint(
                DependencyCheckpoint::KIND_A2A_TASK,
                '',
                '',
                $journalUid,
                (int) $agent->getUid(),
                $task->id,
                $owner,
                $context->actor->workspaceId,
                $now,
                $now + max(1, $agent->getMaxWaitSeconds()),
                $totals['delegations'],
                $totals['transferredBytes'],
            ),
            sprintf('Delegated to the remote A2A agent "%s" as task %s (%s); the run waits for its result.', $agent->getTitle(), $task->id, $task->status->rawState),
        );
    }

    private function description(): string
    {
        $skill = trim($this->skill->name . ': ' . $this->skill->description, ': ');

        return sprintf(
            '[via the remote A2A agent "%s"] %s The run waits until the remote agent answers.',
            $this->agent->getTitle(),
            $skill !== '' ? $skill : $this->skill->id,
        );
    }
}
