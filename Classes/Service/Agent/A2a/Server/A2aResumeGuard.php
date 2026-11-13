<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\RunResumeGuardInterface;
use Netresearch\NrLlm\Service\Agent\RunStartGuardInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Every resume of a run bound to an A2A task checks the client and the
 * profile again (spec 051 A2A-MT4): the client record is still enabled,
 * unexpired and allowed the profile, its technical backend user is the one
 * the run acts as and is still usable, its pinned workspace is the one the
 * run recorded, the profile still exists and is public, and the run's LLM
 * configuration is switched on and one the technical user may use with the
 * groups it has now. Otherwise the run, and with it the task, fails.
 *
 * A writing profile resumes only in a draft workspace (A2A-MT5).
 *
 * The run resumes with the tool allow-list stored when it started; nothing
 * recomputes it. A list that names a tool the profile no longer grants — a
 * tool removed from the profile, or a writing tool after "may write" was
 * cleared — refuses the resume, as a withdrawn skill does: the run fails
 * rather than going on with a capability the profile took back, and rather
 * than silently running with a narrower set than the one it planned with.
 *
 * The same checks run when a worker claims the queued run of a task, before
 * its first segment: a client disabled or a profile withdrawn between the
 * acceptance and the claim fails the run instead of starting it. There the
 * tools compared with the profile are the ones the queued request grants.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aResumeGuard implements RunResumeGuardInterface, RunStartGuardInterface
{
    public function __construct(
        private A2aInboundClientRepository $clients,
        private A2aSkillProfileRepository $profiles,
        private A2aProfileToolList $toolList,
        private AgentRunPersister $persister,
        private ConnectionPool $connectionPool,
        private A2aClientAccess $access,
    ) {}

    public function refusal(AgentRunHandle $handle, AiActorContext $owner): ?string
    {
        [$refusal, $profile] = $this->bindingRefusal($handle, $owner);
        if ($refusal !== null || !$profile instanceof A2aSkillProfile) {
            return $refusal;
        }

        $run    = $this->persister->findRun($handle->uuid);
        $stored = json_decode($run->suspendedState ?? '', true);
        if (!is_array($stored) || !array_key_exists('allowedToolNames', $stored)) {
            return 'the tools the run was granted cannot be read';
        }

        return $this->beyondTheProfile($stored['allowedToolNames'], $profile);
    }

    /**
     * @param list<string>|null $allowedToolNames
     */
    public function startRefusal(AgentRunHandle $handle, AiActorContext $actor, ?array $allowedToolNames): ?string
    {
        [$refusal, $profile] = $this->bindingRefusal($handle, $actor);
        if ($refusal !== null || !$profile instanceof A2aSkillProfile) {
            return $refusal;
        }

        return $this->beyondTheProfile($allowedToolNames, $profile);
    }

    /**
     * Why the client or the profile of the run's task no longer allows it,
     * with the profile when they do. Both null for a run no client's task is
     * bound to.
     *
     * @return array{string|null, A2aSkillProfile|null}
     */
    private function bindingRefusal(AgentRunHandle $handle, AiActorContext $owner): array
    {
        $task = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['owner_client_uid', 'profile_uid'], A2aTaskStore::TABLE, ['run_uid' => $handle->runUid])
            ->fetchAssociative();
        $clientUid = is_array($task) && is_numeric($task['owner_client_uid'] ?? null) ? (int) $task['owner_client_uid'] : 0;
        if (!is_array($task) || $clientUid < 1) {
            // No task, or a task stage 2 bound without a client (tests, a
            // future kind of owner): not this guard's.
            return [null, null];
        }

        $profileUid = is_numeric($task['profile_uid'] ?? null) ? (int) $task['profile_uid'] : 0;
        $client     = $this->clients->findByUid($clientUid);
        $profile    = $this->profiles->findByUid($profileUid);
        $refusal    = $this->clientRefusal($client, $profileUid)
            ?? $this->profileRefusal($profile)
            ?? $this->actorRefusal($client, $profile, $owner)
            ?? $this->configurationRefusal($handle, $client);

        return [$refusal, $refusal === null ? $profile : null];
    }

    private function clientRefusal(?A2aInboundClient $client, int $profileUid): ?string
    {
        if ($client instanceof A2aInboundClient && $client->withdrawnReason !== null) {
            return 'the self-service A2A client is withdrawn: ' . $client->withdrawnReason;
        }

        if (!$client instanceof A2aInboundClient || !$client->isActive(time())) {
            return 'the A2A client is gone, disabled or expired';
        }

        return $client->mayUse($profileUid) ? null : 'the A2A client may no longer call the skill';
    }

    private function profileRefusal(?A2aSkillProfile $profile): ?string
    {
        return $profile instanceof A2aSkillProfile && $profile->public ? null : 'the skill is gone or no longer public';
    }

    /**
     * The technical user and the workspace the run acts in, against the
     * client's record now.
     */
    private function actorRefusal(?A2aInboundClient $client, ?A2aSkillProfile $profile, AiActorContext $owner): ?string
    {
        if (!$client instanceof A2aInboundClient || !$profile instanceof A2aSkillProfile) {
            return 'the A2A client is gone, disabled or expired';
        }

        if ($client->backendUserUid !== $owner->backendUserUid || !$this->usable($client->backendUserUid)) {
            return "the client's technical backend user changed or cannot be used";
        }

        if ($owner->workspaceId !== $client->workspaceId) {
            return "the client's pinned workspace changed";
        }

        return $profile->mayWrite && $client->workspaceId < 1 ? 'a writing skill runs only in a draft workspace' : null;
    }

    /**
     * The check a new task gets (A2aSendMessageHandler), again for every
     * resume: access revoked or a configuration switched off since the task
     * started stops the run.
     */
    private function configurationRefusal(AgentRunHandle $handle, ?A2aInboundClient $client): ?string
    {
        $run = $this->persister->findRun($handle->uuid);
        if ($client instanceof A2aInboundClient && $run instanceof AgentRun && $this->access->mayRun($run->configurationUid, $this->access->actor($client))) {
            return null;
        }

        return "the LLM configuration is switched off or not available to the client's technical user";
    }

    /**
     * Why the granted tool allow-list exceeds what the profile grants now, or
     * null when it does not.
     */
    private function beyondTheProfile(mixed $granted, A2aSkillProfile $profile): ?string
    {
        if (!is_array($granted)) {
            // Null is every enabled tool: never a profile's grant.
            return 'the run was granted tools beyond its skill';
        }

        $allowed = $this->toolList->allowedFor($profile);
        foreach ($granted as $tool) {
            if (!is_string($tool) || !in_array($tool, $allowed, true)) {
                return 'the skill no longer grants a tool the run was given';
            }
        }

        return null;
    }

    /**
     * The technical user as `A2aAuthenticator` takes it at request time: not
     * an administrator, not disabled or deleted, and inside its start and end
     * time.
     */
    private function usable(int $uid): bool
    {
        if ($uid < 1) {
            return false;
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('admin', 'disable', 'deleted', 'starttime', 'endtime')->from('be_users')
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return false;
        }

        foreach (['admin', 'disable', 'deleted'] as $flag) {
            if (!is_numeric($row[$flag] ?? null) || (int) $row[$flag] !== 0) {
                return false;
            }
        }

        $now       = time();
        $starttime = is_numeric($row['starttime'] ?? null) ? (int) $row['starttime'] : 0;
        $endtime   = is_numeric($row['endtime'] ?? null) ? (int) $row['endtime'] : 0;

        return ($starttime === 0 || $starttime <= $now) && ($endtime === 0 || $endtime > $now);
    }
}
