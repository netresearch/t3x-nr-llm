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
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aHandlerDecision;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aRunContext;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsRepository;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 * Stage 3 does not continue a task: a message that names a task is checked
 * like stage 4 will check it, and then refused as unsupported.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendMessageHandler
{
    /** The profile selector extension (A2A-SV4a); declared on the card, not required. */
    public const PROFILE_EXTENSION = 'https://nr-llm.netresearch.de/a2a/ext/profile/v1';

    /** Parts a message may carry (A2A-SEC6). */
    public const MAX_PARTS = 16;

    private const DEDUP_TABLE = 'tx_nrllm_a2a_dedup';

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
        private ToolEffectResolver $effects,
        private WorkerOperationsRepository $workers,
        private A2aWaitingSlots $slots,
        private A2aServerSettings $settings,
        private A2aTaskWaiter $waiter,
        private ConnectionPool $connectionPool,
        ?LoggerInterface $logger = null,
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
            throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', sprintf('the skill "%s" is not one this client may call', $named), A2aRpcError::REASON_PROFILE_NOT_ALLOWED);
        }

        if ($message->taskId !== '') {
            $this->refuseContinuation($client, $message, $named);
        }

        $profile = $this->selectProfile($allowed, $named);
        $this->checkOutputModes($configuration, $profile);
        $contextId = $this->contextId($client, $message);

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

        $actor    = $this->actor($client);
        $decision = $handler->decide($message, $profile, new A2aRunContext($llm, $actor, $this->readOnlyTools($profile), ''));
        if ($decision->reply instanceof A2aMessage) {
            return ['message' => $decision->reply->toArray()];
        }

        $this->checkCapacity($client, $profile, $now);
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
        $entry              = $message->toArray();
        $entry['taskId']    = $taskId;
        $entry['contextId'] = $contextId;
        $history            = [$entry];

        try {
            $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)->transactional(
                function (Connection $connection) use ($client, $profile, $message, $contextId, $digest, $decision, $request, $taskId, $history, &$runUuid): void {
                    // The limits checked before are checked again under the
                    // client's (and the quota profile's) row lock, so
                    // concurrent requests of one client are serialised here
                    // and none of them can pass a count the others changed.
                    $this->lockRow($connection, A2aInboundClientRepository::TABLE, $client->uid);
                    if ($profile->maxActiveTasks > 0) {
                        $this->lockRow($connection, A2aSkillProfileRepository::TABLE, $profile->uid);
                    }

                    $this->checkLimits($client, $profile);

                    $runUuid = $this->runtime->defer($request);
                    $run     = $this->persister->findRun($runUuid);
                    if (!$run instanceof AgentRun) {
                        throw new A2aException('The queued run of an A2A task cannot be read back.', 1799300201);
                    }

                    $taskUid = $this->store->bind($run->uid, $taskId, $contextId, $client->uid, $profile->uid, $history);
                    $connection->insert(self::DEDUP_TABLE, [
                        'owner_client_uid' => $client->uid,
                        'message_id'       => $message->messageId,
                        'message_sha256'   => $digest,
                        'task_uid'         => $taskUid,
                        'crdate'           => time(),
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
     * Stage 3 runs a task once; a later turn is stage 4 (A2A-MT3). The checks
     * a continuation will need come first, in the order A2A-SV4a and
     * A2A-SV6 give.
     */
    private function refuseContinuation(A2aInboundClient $client, A2aMessage $message, ?string $named): never
    {
        $row = $this->view->find($client->uid, $message->taskId);
        if ($row === null) {
            throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_FOUND, 'Task not found.');
        }

        if ($message->contextId !== '' && $message->contextId !== ($row['context_id'] ?? '')) {
            throw A2aRpcError::invalidParams('message.contextId', 'the context does not match the task');
        }

        $profileUid = is_numeric($row['profile_uid'] ?? null) ? (int) $row['profile_uid'] : 0;
        $profile    = $this->profiles->findByUid($profileUid);
        if ($named !== null && (!$profile instanceof A2aSkillProfile || $profile->skillId !== $named)) {
            throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', "the skill differs from the task's", A2aRpcError::REASON_PROFILE_MISMATCH);
        }

        $state = A2aTaskState::tryFrom(is_string($row['state'] ?? null) ? $row['state'] : '');
        if ($state instanceof A2aTaskState && $state->isTerminal()) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is in a terminal state.');
        }

        throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'Continuing a task is not supported.', A2aRpcError::REASON_CONTINUATION_NOT_SUPPORTED);
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
            throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', 'this client may call no skill', A2aRpcError::REASON_PROFILE_NOT_ALLOWED);
        }

        throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', 'this client may call several skills; name one', A2aRpcError::REASON_PROFILE_REQUIRED);
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
            throw A2aRpcError::invalidParams('metadata["' . self::PROFILE_EXTENSION . '"]', 'not a skill id');
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
    private function contextId(A2aInboundClient $client, A2aMessage $message): string
    {
        if ($message->contextId === '') {
            return Uuid::v4()->toRfc4122();
        }

        if ($this->view->ownsContext($client->uid, $message->contextId)) {
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
        $row        = $connection->select(['message_sha256', 'task_uid'], self::DEDUP_TABLE, ['owner_client_uid' => $client->uid, 'message_id' => $message->messageId])->fetchAssociative();
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
     * The profile's tools that declare that they only read; a write is stage
     * 4 (A2A-SV4). A tool that declares no effect is left out: the resolver
     * reads it as a read for its retry guards, but it may write (ADR-238).
     *
     * @return list<string>
     */
    private function readOnlyTools(A2aSkillProfile $profile): array
    {
        return array_values(array_filter($profile->tools, $this->effects->declaresRead(...)));
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
