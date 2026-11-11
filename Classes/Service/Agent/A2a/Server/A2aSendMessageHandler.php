<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPartKind;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aContinuationObserverInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aHandlerDecision;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aRunContext;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aInputMapping;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\DurableDecisionIntakeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsRepository;
use Netresearch\NrLlm\Service\Agent\PendingPause;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Agent\Queue\QueueRouting;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * `SendMessage` of the server role (spec 051 A2A-SV4a to A2A-SV9).
 *
 * Everything that can refuse a call is checked before anything is stored, in
 * this order: the params, the profile (by the client record only, before any
 * lookup of a task), a named task, the context, a repeated messageId, the
 * profile's answer level, the handler's decision, then the capacity. The
 * dedup record, the task, the run and the binding then commit in one
 * transaction, and the response is built only after the commit (A2A-SV5).
 * The run is queued with `defer()` as the client's technical backend user;
 * nothing here executes the agent loop (A2A-SV7).
 *
 * A message that names a task continues it (A2A-MT3): it is checked in the
 * order A2A-SV4a and A2A-SV6 give, then the task must wait for the client
 * (INPUT_REQUIRED), and the client and the profile are checked again
 * (A2A-MT4). The dedup record, the message in the task's history and the
 * stored input (A2A-MT2) then commit in one transaction under the client's
 * row lock; the worker carries the input out.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendMessageHandler
{
    /** The profile selector extension (A2A-SV4a); declared on the card, not required. */
    public const PROFILE_EXTENSION = 'https://nr-llm.netresearch.de/a2a/ext/profile/v1';

    /** The request field that names the skill, in error data. */
    private const PROFILE_FIELD = 'metadata["' . self::PROFILE_EXTENSION . '"]';

    /** Parts a message may carry (A2A-SEC6). */
    public const MAX_PARTS = 16;

    private const DEDUP_TABLE = 'tx_nrllm_a2a_dedup';

    private const RUN_TABLE = 'tx_nrllm_agentrun';

    private const MAX_MESSAGE_ID_LENGTH = 255;

    private const ACCEPTED_TEXT_TYPES = ['', 'text/plain'];

    private LoggerInterface $logger;

    public function __construct(
        private A2aTaskView $view,
        private A2aSkillProfileRepository $profiles,
        private A2aDomainHandlerRegistry $handlers,
        private A2aTaskStore $store,
        private AgentRuntimeInterface $runtime,
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurations,
        private RunPrivacyLevelResolver $privacy,
        private A2aProfileToolList $toolList,
        private WorkerOperationsRepository $workers,
        private A2aWaitingSlots $slots,
        private A2aServerSettings $settings,
        private A2aTaskWaiter $waiter,
        private ConnectionPool $connectionPool,
        private DurableDecisionIntakeInterface $intake,
        private ConfigurationResolver $access,
        ?LoggerInterface $logger = null,
        private ?MessageBusInterface $messageBus = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the `SendMessageResponse`
     *
     * @throws A2aRpcError
     */
    public function handle(A2aInboundClient $client, array $params, int $now): array
    {
        $this->checkConnections($client);

        $message       = $this->message($params);
        $configuration = $this->object($params, 'configuration');
        if (array_key_exists('taskPushNotificationConfig', $configuration)) {
            throw A2aRpcError::a2a(A2aErrorCode::PUSH_NOTIFICATION_NOT_SUPPORTED, 'Push notifications are not supported.');
        }

        $blocking      = !$this->bool($configuration, 'returnImmediately', 'configuration.returnImmediately');
        $historyLength = $this->historyLength($configuration);

        $allowed = $this->profiles->findAllowedFor($client);
        $named   = $this->namedSkill($params);
        if ($named !== null && !$this->hasSkill($allowed, $named)) {
            throw A2aRpcError::invalidParams(self::PROFILE_FIELD, sprintf('the skill "%s" is not one this client may call', $named), A2aRpcError::REASON_PROFILE_NOT_ALLOWED);
        }

        if ($message->taskId !== '') {
            return $this->continueTask($client, $message, $named, $allowed, $configuration, $blocking, $historyLength, $now);
        }

        $profile = $this->selectProfile($allowed, $named);
        $this->checkOutputModes($configuration, $profile);
        $contextId = $this->contextId($client, $message, $profile);

        $digest   = hash('sha256', json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $existing = $this->deduplicated($client, $message, $digest);
        if ($existing !== null) {
            return $this->respond($client, $existing, $blocking, $historyLength, null);
        }

        if (!$this->privacy->forConfigurationAnswer($profile->configurationUid)->persistsContent()) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'This skill cannot keep a result under the privacy policy of this installation.', A2aRpcError::REASON_RESULT_NOT_RETAINABLE);
        }

        $handler = $this->handlers->get($profile->handler);
        $llm     = $this->configurations->findByUid($profile->configurationUid);
        if (!$handler instanceof A2aDomainHandlerInterface || !$llm instanceof LlmConfiguration) {
            $this->logger->error('A2A profile cannot be executed: its handler or its LLM configuration is missing', ['profile' => $profile->uid]);

            throw A2aRpcError::internal();
        }

        // A2A-MT5: a writing skill writes drafts only, into the client's
        // pinned workspace; live is never a target.
        if ($profile->mayWrite && $client->workspaceId < 1) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'This skill writes and needs a client pinned to a draft workspace.');
        }

        $actor = $this->actor($client);
        if (!$llm->isActive() || !$this->access->actorMayUse($llm, $actor)) {
            // Every entry point that starts a run checks the configuration
            // for its actor; here the actor is the client's technical user.
            $this->logger->warning("A2A profile cannot be executed: its LLM configuration is switched off or not available to the client's technical user", ['profile' => $profile->uid, 'client' => $client->uid]);

            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'This skill is not available.', A2aRpcError::REASON_PROFILE_UNAVAILABLE);
        }

        $decision = $handler->decide($message, $profile, new A2aRunContext($llm, $actor, $this->allowedTools($profile), ''));
        if ($decision->reply instanceof A2aMessage) {
            return ['message' => $decision->reply->toArray()];
        }

        try {
            $this->checkCapacity($client, $profile, $now);
        } catch (A2aRpcError $refused) {
            // A concurrent request with the same messageId may have taken the
            // capacity this one was refused for: then its task is the answer
            // (A2A-SV6).
            $winner = $this->deduplicated($client, $message, $digest);
            if ($winner !== null) {
                return $this->respond($client, $winner, $blocking, $historyLength, null);
            }

            throw $refused;
        }

        $slot = $blocking ? $this->takeSlot($now) : null;

        try {
            $taskId = $this->accept($client, $profile, $message, $contextId, $digest, $decision);
            if ($taskId === null) {
                // A concurrent request with the same messageId won the
                // acceptance; this one returns its task (A2A-SV6).
                $winner = $this->deduplicated($client, $message, $digest);
                if ($winner === null) {
                    throw A2aRpcError::internal();
                }

                return $this->respond($client, $winner, $blocking, $historyLength, $slot);
            }

            return $this->respond($client, $taskId, $blocking, $historyLength, $slot);
        } finally {
            if ($slot !== null) {
                $this->slots->release($slot);
            }
        }
    }

    /**
     * The acceptance transaction (A2A-SV5): the run, the task bound to it and
     * the dedup record, or none of them.
     *
     * @return string|null the new task's id; null when the dedup record of a concurrent request won
     */
    private function accept(A2aInboundClient $client, A2aSkillProfile $profile, A2aMessage $message, string $contextId, string $digest, A2aHandlerDecision $decision): ?string
    {
        $request = $decision->request;
        if (!$request instanceof AgentRunRequest) {
            throw A2aRpcError::internal();
        }

        $taskId             = Uuid::v4()->toRfc4122();
        $runUuid            = '';
        $repeated           = false;
        $entry              = $message->toArray();
        $entry['taskId']    = $taskId;
        $entry['contextId'] = $contextId;
        $history            = [$entry];

        try {
            $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)->transactional(
                function (Connection $connection) use ($client, $profile, $message, $contextId, $digest, $decision, $request, $taskId, $history, &$runUuid, &$repeated): void {
                    // The limits checked before are checked again under the
                    // client's (and the quota profile's) row lock, so
                    // concurrent requests of one client are serialised here
                    // and none of them can pass a count the others changed.
                    $this->lockRow($connection, A2aInboundClientRepository::TABLE, $client->uid);
                    if ($profile->maxActiveTasks > 0) {
                        $this->lockRow($connection, A2aSkillProfileRepository::TABLE, $profile->uid);
                    }

                    // A request with the same messageId that committed while
                    // this one waited for the lock: its task is the answer, not
                    // a limit it used up.
                    if ($connection->count('uid', self::DEDUP_TABLE, ['owner_client_uid' => $client->uid, 'message_id_sha256' => hash('sha256', $message->messageId)]) > 0) {
                        $repeated = true;

                        return;
                    }

                    $this->checkLimits($client, $profile);

                    $runUuid = $this->runtime->defer($request);
                    $run     = $this->persister->findRun($runUuid);
                    if (!$run instanceof AgentRun) {
                        throw new A2aException('The queued run of an A2A task cannot be read back.', 1799300201);
                    }

                    $taskUid = $this->store->bind($run->uid, $taskId, $contextId, $client->uid, $profile->uid, $history);
                    $connection->insert(self::DEDUP_TABLE, [
                        'owner_client_uid'  => $client->uid,
                        'message_id'        => $message->messageId,
                        'message_id_sha256' => hash('sha256', $message->messageId),
                        'message_sha256'    => $digest,
                        'task_uid'          => $taskUid,
                        'crdate'            => time(),
                    ]);

                    if ($decision->rejected && !$this->persister->settlePolicyStopped(new AgentRunHandle($run->uid, $run->uuid), new A2aInputRefusedException('The input was refused before the run started.', 1799300202), AgentRunTerminationReason::POLICY_DENIED)) {
                        throw new A2aException('A refused A2A task could not be settled.', 1799300203);
                    }
                },
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (A2aRpcError $refusal) {
            throw $refusal;
        } catch (Throwable $exception) {
            $this->logger->error('An A2A task could not be accepted; nothing was stored', ['client' => $client->uid, 'exception' => $exception]);

            throw A2aRpcError::internal();
        }

        if ($repeated) {
            return null;
        }

        if (!$decision->rejected) {
            $this->wake($runUuid);
        }

        if ($decision->afterCommit instanceof Closure) {
            try {
                ($decision->afterCommit)($runUuid);
            } catch (Throwable $exception) {
                $this->logger->warning('The after-commit step of an A2A handler failed', ['exception' => $exception]);
            }
        }

        return $taskId;
    }

    /**
     * The tables the acceptance writes and locks share one connection, or the
     * transaction could not take all of them back (A2A-SV5).
     */
    private function checkConnections(A2aInboundClient $client): void
    {
        $connection = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE);
        foreach ([self::DEDUP_TABLE, self::RUN_TABLE, A2aInboundClientRepository::TABLE, A2aSkillProfileRepository::TABLE] as $table) {
            if ($this->connectionPool->getConnectionForTable($table) !== $connection) {
                $this->logger->error('An A2A task cannot be accepted: its tables are on different database connections', ['client' => $client->uid, 'table' => $table]);

                throw A2aRpcError::internal();
            }
        }
    }

    /**
     * Wake a consumer once the run is committed. The dispatch inside the run's
     * creation can reach a consumer before the acceptance commits, when the
     * transport is not this database; the consumer then finds no run. A
     * second message after the commit is harmless: the claim decides.
     */
    private function wake(string $runUuid): void
    {
        if (!$this->messageBus instanceof MessageBusInterface || !QueueRouting::leavesTheProcess()) {
            return;
        }

        try {
            $this->messageBus->dispatch(new AgentRunQueuedMessage($runUuid));
        } catch (Throwable $exception) {
            $this->logger->warning('An accepted A2A task could not wake a consumer; the sweep will pick it up', ['run' => $runUuid, 'exception' => $exception]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function respond(A2aInboundClient $client, string $taskId, bool $blocking, ?int $historyLength, ?string $slot): array
    {
        if (!$blocking) {
            $row = $this->view->find($client->uid, $taskId);

            return ['task' => $this->view->render($row ?? throw A2aRpcError::internal(), $historyLength)];
        }

        if ($slot === null) {
            $slot = $this->takeSlot(time());
            try {
                return $this->respond($client, $taskId, true, $historyLength, $slot);
            } finally {
                $this->slots->release($slot);
            }
        }

        $row = $this->waiter->wait($client->uid, $taskId, microtime(true) + $this->settings->blockingWaitSeconds());
        if ($row === null) {
            throw A2aRpcError::deadlineExceeded($taskId);
        }

        return ['task' => $this->view->render($row, $historyLength)];
    }

    /**
     * A client's later message to one of its tasks (A2A-MT1 to A2A-MT4).
     *
     * @param list<A2aSkillProfile> $allowed
     * @param array<string, mixed>  $configuration
     *
     * @return array<string, mixed>
     *
     * @throws A2aRpcError
     */
    private function continueTask(A2aInboundClient $client, A2aMessage $message, ?string $named, array $allowed, array $configuration, bool $blocking, ?int $historyLength, int $now): array
    {
        $row = $this->view->find($client->uid, $message->taskId);
        if ($row === null) {
            throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_FOUND, 'Task not found.');
        }

        $contextId = is_string($row['context_id'] ?? null) ? $row['context_id'] : '';
        if ($message->contextId !== '' && $message->contextId !== $contextId) {
            throw A2aRpcError::invalidParams('message.contextId', 'the context does not match the task');
        }

        $profileUid = is_numeric($row['profile_uid'] ?? null) ? (int) $row['profile_uid'] : 0;
        $profile    = $this->profiles->findByUid($profileUid);
        if ($named !== null && (!$profile instanceof A2aSkillProfile || $profile->skillId !== $named)) {
            throw A2aRpcError::invalidParams(self::PROFILE_FIELD, "the skill differs from the task's", A2aRpcError::REASON_PROFILE_MISMATCH);
        }

        // The message carries the task's context from here on (§3.4.3). A
        // repeat of an answer already taken in returns the task, also once
        // the task has ended: a client that lost the response retries.
        $message = $message->withContextId($contextId);
        $digest  = hash('sha256', json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $known   = $this->deduplicated($client, $message, $digest);
        if ($known !== null) {
            return $this->respond($client, $known, $blocking, $historyLength, null);
        }

        $state = A2aTaskState::tryFrom(is_string($row['state'] ?? null) ? $row['state'] : '');
        if ($state instanceof A2aTaskState && $state->isTerminal()) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is in a terminal state.');
        }

        // A2A-MT4: the profile is one this client may still call.
        if (!$profile instanceof A2aSkillProfile || !$this->hasSkill($allowed, $profile->skillId)) {
            throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', 'the skill of this task is not one this client may call', A2aRpcError::REASON_PROFILE_NOT_ALLOWED);
        }

        if ($state !== A2aTaskState::INPUT_REQUIRED && $state !== A2aTaskState::AUTH_REQUIRED) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is not waiting for a message from the client.');
        }

        $this->checkOutputModes($configuration, $profile);

        $runUid  = is_numeric($row['run_uid'] ?? null) ? (int) $row['run_uid'] : 0;
        $runUuid = $this->runUuid($runUid);
        $actor   = $this->actor($client);
        $pause   = $runUuid !== '' ? $this->intake->pendingPause($actor, $runUuid) : null;
        if (!$pause instanceof PendingPause || !$pause->isInput()) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is not waiting for a message from the client.');
        }

        $data = A2aInputMapping::submission($message->text(), $pause->state->inputSchema);
        if ($data === null) {
            throw A2aRpcError::invalidParams('message.parts', 'the text does not answer the question the task asks');
        }

        $this->checkCapacity($client, $profile, $now);
        $slot = $blocking ? $this->takeSlot($now) : null;

        try {
            $taskId = $this->takeIn($client, $profile, $message, $digest, $row, $runUid, $runUuid, $actor, $pause, $data);
            if ($taskId === null) {
                $winner = $this->deduplicated($client, $message, $digest);
                if ($winner === null) {
                    throw A2aRpcError::internal();
                }

                return $this->respond($client, $winner, $blocking, $historyLength, $slot);
            }

            $handler = $this->handlers->get($profile->handler);
            if ($handler instanceof A2aContinuationObserverInterface) {
                try {
                    $handler->continued($runUuid, $message);
                } catch (Throwable $exception) {
                    $this->logger->warning('The continuation step of an A2A handler failed', ['exception' => $exception]);
                }
            }

            return $this->respond($client, $taskId, $blocking, $historyLength, $slot);
        } finally {
            if ($slot !== null) {
                $this->slots->release($slot);
            }
        }
    }

    /**
     * The intake transaction of a continuation (A2A-MT2, A2A-MT3): the dedup
     * record, the message in the history and the stored input, or none of
     * them. Concurrent messages of one client are serialised by its row lock;
     * the stored input's guarded transition lets exactly one of several
     * messages to the same pause through.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     *
     * @return string|null the task's id; null when the dedup record of a concurrent request won
     */
    private function takeIn(A2aInboundClient $client, A2aSkillProfile $profile, A2aMessage $message, string $digest, array $row, int $runUid, string $runUuid, AiActorContext $actor, PendingPause $pause, array $data): ?string
    {
        $taskId  = is_string($row['task_id'] ?? null) ? $row['task_id'] : '';
        $taskUid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;

        try {
            $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)->transactional(
                function (Connection $connection) use ($client, $profile, $message, $digest, $taskId, $taskUid, $runUid, $runUuid, $actor, $pause, $data): void {
                    $this->lockRow($connection, A2aInboundClientRepository::TABLE, $client->uid);
                    if ($profile->maxActiveTasks > 0) {
                        $this->lockRow($connection, A2aSkillProfileRepository::TABLE, $profile->uid);
                    }

                    $this->checkLimits($client, $profile);

                    $connection->insert(self::DEDUP_TABLE, [
                        'owner_client_uid'  => $client->uid,
                        'message_id'        => $message->messageId,
                        'message_id_sha256' => hash('sha256', $message->messageId),
                        'message_sha256'    => $digest,
                        'task_uid'          => $taskUid,
                        'crdate'            => time(),
                    ]);

                    $entry           = $message->toArray();
                    $entry['taskId'] = $taskId;
                    $this->store->appendHistory($runUid, $entry);

                    $this->intake->storeInput($actor, $runUuid, new InputSubmission($data, $client->backendUserUid, $pause->turnDigest), $pause->generation);
                },
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (A2aRpcError $refusal) {
            throw $refusal;
        } catch (InvalidInputSubmissionException) {
            throw A2aRpcError::invalidParams('message.parts', 'the text does not answer the question the task asks');
        } catch (AgentRuntimeException $refusal) {
            // The pause moved on, another message took it, or the client's
            // user may no longer answer it: nothing was stored.
            $this->logger->notice('A continuation of an A2A task was not taken in', ['client' => $client->uid, 'reason' => $refusal->getMessage()]);

            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is not waiting for a message from the client.');
        } catch (Throwable $exception) {
            $this->logger->error('A continuation of an A2A task could not be stored; nothing was stored', ['client' => $client->uid, 'exception' => $exception]);

            throw A2aRpcError::internal();
        }

        return $taskId;
    }

    private function runUuid(int $runUid): string
    {
        if ($runUid < 1) {
            return '';
        }

        $uuid = $this->connectionPool->getConnectionForTable('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne();

        return is_string($uuid) ? $uuid : '';
    }

    /**
     * @param list<A2aSkillProfile> $allowed
     */
    private function selectProfile(array $allowed, ?string $named): A2aSkillProfile
    {
        if ($named !== null) {
            foreach ($allowed as $profile) {
                if ($profile->skillId === $named) {
                    return $profile;
                }
            }
        }

        if (count($allowed) === 1) {
            return $allowed[0];
        }

        if ($allowed === []) {
            throw A2aRpcError::invalidParams(self::PROFILE_FIELD, 'this client may call no skill', A2aRpcError::REASON_PROFILE_NOT_ALLOWED);
        }

        throw A2aRpcError::invalidParams(self::PROFILE_FIELD, 'this client may call several skills; name one', A2aRpcError::REASON_PROFILE_REQUIRED);
    }

    /**
     * @param list<A2aSkillProfile> $allowed
     */
    private function hasSkill(array $allowed, string $skillId): bool
    {
        foreach ($allowed as $profile) {
            if ($profile->skillId === $skillId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function namedSkill(array $params): ?string
    {
        $metadata = $this->object($params, 'metadata');
        if (!array_key_exists(self::PROFILE_EXTENSION, $metadata)) {
            return null;
        }

        $value = $metadata[self::PROFILE_EXTENSION];
        if (!is_string($value) || $value === '') {
            throw A2aRpcError::invalidParams(self::PROFILE_FIELD, 'not a skill id');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function message(array $params): A2aMessage
    {
        if (!is_array($params['message'] ?? null) || array_is_list($params['message']) && $params['message'] !== []) {
            throw A2aRpcError::invalidParams('message', 'required');
        }

        /** @var array<string, mixed> $json */
        $json = $params['message'];
        try {
            $message = (new A2aResponseParser())->parseMessage($json);
        } catch (A2aInvalidAgentResponseException $exception) {
            throw A2aRpcError::invalidParams($exception->field() !== '' ? $exception->field() : 'message', 'missing or invalid');
        }

        if ($message->role !== A2aRole::USER) {
            throw A2aRpcError::invalidParams('message.role', 'a client message has the role ROLE_USER');
        }

        // The dedup record keeps the id in a column of this width.
        if (mb_strlen($message->messageId) > self::MAX_MESSAGE_ID_LENGTH) {
            throw A2aRpcError::invalidParams('message.messageId', sprintf('at most %d characters', self::MAX_MESSAGE_ID_LENGTH));
        }

        if ($message->parts === []) {
            throw A2aRpcError::invalidParams('message.parts', 'at least one part of a known kind is required');
        }

        if (count($message->parts) > self::MAX_PARTS) {
            throw A2aRpcError::invalidParams('message.parts', sprintf('at most %d parts', self::MAX_PARTS));
        }

        foreach ($message->parts as $part) {
            if (!$this->isPlainText($part)) {
                throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, 'Only text/plain parts are accepted.');
            }
        }

        return $message;
    }

    private function isPlainText(A2aPart $part): bool
    {
        return $part->kind === A2aPartKind::TEXT && in_array(strtolower(trim(explode(';', $part->mediaType)[0])), self::ACCEPTED_TEXT_TYPES, true);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function checkOutputModes(array $configuration, A2aSkillProfile $profile): void
    {
        $accepted = $configuration['acceptedOutputModes'] ?? [];
        if (!is_array($accepted) || array_filter($accepted, static fn (mixed $mode): bool => !is_string($mode)) !== []) {
            throw A2aRpcError::invalidParams('configuration.acceptedOutputModes', 'not a list of media types');
        }

        if ($accepted !== [] && array_intersect(array_map(static fn (mixed $mode): string => strtolower(is_string($mode) ? $mode : ''), $accepted), $profile->outputModes) === []) {
            throw A2aRpcError::a2a(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED, 'None of the accepted output modes is one this skill produces.');
        }
    }

    /**
     * A client-supplied context is accepted when the client owns it or it is
     * a new UUID; otherwise one is generated (A2A-SV6, §3.4.1).
     */
    private function contextId(A2aInboundClient $client, A2aMessage $message, A2aSkillProfile $profile): string
    {
        if ($message->contextId === '') {
            return Uuid::v4()->toRfc4122();
        }

        if ($this->view->ownsContext($client->uid, $message->contextId)) {
            // A context is not crossed between the client's skills: a task of
            // another profile in it would read as one conversation.
            if (!$this->view->contextHoldsOnlyProfile($client->uid, $message->contextId, $profile->uid)) {
                throw A2aRpcError::invalidParams('message.contextId', 'the context belongs to another skill', A2aRpcError::REASON_PROFILE_MISMATCH);
            }

            return $message->contextId;
        }

        if (Uuid::isValid($message->contextId) && !$this->view->contextExists($message->contextId)) {
            return $message->contextId;
        }

        throw A2aRpcError::invalidParams('message.contextId', 'not a context of this client and not a new UUID');
    }

    /**
     * The task a repeated messageId of this client created (A2A-SV6); the
     * same id with another message is refused.
     */
    private function deduplicated(A2aInboundClient $client, A2aMessage $message, string $digest): ?string
    {
        $connection = $this->connectionPool->getConnectionForTable(self::DEDUP_TABLE);
        // By the hash of the id: compared byte for byte whatever the collation.
        $row = $connection->select(['message_sha256', 'task_uid'], self::DEDUP_TABLE, ['owner_client_uid' => $client->uid, 'message_id_sha256' => hash('sha256', $message->messageId)])->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        if (!hash_equals(is_string($row['message_sha256'] ?? null) ? $row['message_sha256'] : '', $digest)) {
            throw A2aRpcError::invalidParams('message.messageId', 'this messageId was used for another message');
        }

        $taskId = $connection->select(['task_id'], A2aTaskStore::TABLE, ['uid' => is_numeric($row['task_uid'] ?? null) ? (int) $row['task_uid'] : 0, 'owner_client_uid' => $client->uid])->fetchOne();

        return is_string($taskId) && $taskId !== '' ? $taskId : null;
    }

    /**
     * A2A-SV9: a worker shown to run, the client's and the profile's limits.
     * The waiting slot of a blocking call is taken separately.
     */
    private function checkCapacity(A2aInboundClient $client, A2aSkillProfile $profile, int $now): void
    {
        if ($this->workers->liveProcesses($now) < 1) {
            throw A2aRpcError::unavailable(A2aRpcError::REASON_WORKER_UNAVAILABLE, 60);
        }

        $this->checkLimits($client, $profile);
    }

    /**
     * The client's task limit and the profile's quota. Asked once before
     * anything is done, and again inside the acceptance transaction under the
     * row locks, where it decides.
     */
    private function checkLimits(A2aInboundClient $client, A2aSkillProfile $profile): void
    {
        if ($this->view->countActiveOfClient($client->uid) >= $client->taskLimit) {
            throw A2aRpcError::unavailable(A2aRpcError::REASON_CLIENT_CAPACITY_EXHAUSTED, 30);
        }

        if ($profile->maxActiveTasks > 0 && $this->view->countActiveOfProfile($profile->uid) >= $profile->maxActiveTasks) {
            throw A2aRpcError::unavailable(A2aRpcError::REASON_PROFILE_CAPACITY_EXHAUSTED, 30);
        }
    }

    /**
     * A no-op UPDATE: a row lock on InnoDB, the write lock on SQLite. Held
     * until the acceptance transaction ends.
     */
    private function lockRow(Connection $connection, string $table, int $uid): void
    {
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier($table) . ' SET uid = uid WHERE uid = ?',
            [$uid],
            [Connection::PARAM_INT],
        );
    }

    private function takeSlot(int $now): string
    {
        $wait = $this->settings->blockingWaitSeconds();
        $slot = $this->slots->take($this->settings->maxWaitingRequests(), $now, $now + $wait + 30);
        if ($slot === null) {
            throw A2aRpcError::unavailable(A2aRpcError::REASON_WAITING_CAPACITY_EXHAUSTED, $wait);
        }

        return $slot;
    }

    /**
     * The client's technical backend user in the client's pinned workspace
     * (A2A-SEC3, ADR-280); its groups decide which configurations it may use.
     */
    private function actor(A2aInboundClient $client): AiActorContext
    {
        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $groups = $builder->select('usergroup')->from('be_users')
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($client->backendUserUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        $groupIds = array_values(array_filter(array_map(intval(...), explode(',', is_string($groups) ? $groups : '')), static fn (int $uid): bool => $uid > 0));

        return AiActorContext::backendUser($client->backendUserUid, false, $groupIds, [], $client->workspaceId);
    }

    /**
     * The profile's tools: all of them for a writing profile (A2A-MT5),
     * otherwise those that declare that they only read (A2A-SV4). A tool
     * that declares no effect is left out: the resolver reads it as a read
     * for its retry guards, but it may write (ADR-238).
     *
     * @return list<string>
     */
    private function allowedTools(A2aSkillProfile $profile): array
    {
        return $this->toolList->allowedFor($profile);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function object(array $json, string $key): array
    {
        $value = $json[$key] ?? [];
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw A2aRpcError::invalidParams($key, 'not an object');
        }

        $object = [];
        foreach ($value as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function bool(array $json, string $key, string $path): bool
    {
        $value = $json[$key] ?? false;
        if (!is_bool($value)) {
            throw A2aRpcError::invalidParams($path, 'not a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function historyLength(array $configuration): ?int
    {
        if (!array_key_exists('historyLength', $configuration) || $configuration['historyLength'] === null) {
            return null;
        }

        $value = $configuration['historyLength'];
        if (!is_int($value) || $value < 0) {
            throw A2aRpcError::invalidParams('configuration.historyLength', 'not a non-negative integer');
        }

        return $value;
    }
}
