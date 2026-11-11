<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\RunResumeGuardInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Every resume of a run bound to an A2A task checks the client and the
 * profile again (spec 051 A2A-MT4): the client record is still enabled,
 * unexpired and allowed the profile, its technical backend user is the one
 * the run acts as and is still usable, its pinned workspace is the one the
 * run recorded, and the profile still exists and is public. Otherwise the
 * run, and with it the task, fails.
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
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aResumeGuard implements RunResumeGuardInterface
{
    public function __construct(
        private A2aInboundClientRepository $clients,
        private A2aSkillProfileRepository $profiles,
        private A2aProfileToolList $toolList,
        private AgentRunPersister $persister,
        private ConnectionPool $connectionPool,
    ) {}

    public function refusal(AgentRunHandle $handle, AiActorContext $owner): ?string
    {
        $task = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['owner_client_uid', 'profile_uid'], A2aTaskStore::TABLE, ['run_uid' => $handle->runUid])
            ->fetchAssociative();
        if (!is_array($task)) {
            return null;
        }

        $clientUid  = is_numeric($task['owner_client_uid'] ?? null) ? (int) $task['owner_client_uid'] : 0;
        $profileUid = is_numeric($task['profile_uid'] ?? null) ? (int) $task['profile_uid'] : 0;
        if ($clientUid < 1) {
            // A task stage 2 bound without a client (tests, a future kind of
            // owner) is not this guard's.
            return null;
        }

        $client = $this->clients->findByUid($clientUid);
        if (!$client instanceof A2aInboundClient || !$client->isActive(time())) {
            return 'the A2A client is gone, disabled or expired';
        }

        if (!$client->mayUse($profileUid)) {
            return 'the A2A client may no longer call the skill';
        }

        $profile = $this->profiles->findByUid($profileUid);
        if (!$profile instanceof A2aSkillProfile || !$profile->public) {
            return 'the skill is gone or no longer public';
        }

        if ($client->backendUserUid !== $owner->backendUserUid || !$this->usable($client->backendUserUid)) {
            return "the client's technical backend user changed or cannot be used";
        }

        if ($owner->workspaceId !== $client->workspaceId) {
            return "the client's pinned workspace changed";
        }

        if ($profile->mayWrite && $client->workspaceId < 1) {
            return 'a writing skill runs only in a draft workspace';
        }

        return $this->beyondTheProfile($handle, $profile);
    }

    /**
     * Why the stored tool allow-list exceeds what the profile grants now, or
     * null when it does not.
     */
    private function beyondTheProfile(AgentRunHandle $handle, A2aSkillProfile $profile): ?string
    {
        $run    = $this->persister->findRun($handle->uuid);
        $stored = json_decode($run->suspendedState ?? '', true);
        if (!is_array($stored) || !array_key_exists('allowedToolNames', $stored)) {
            return 'the tools the run was granted cannot be read';
        }

        $granted = $stored['allowedToolNames'];
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

    private function usable(int $uid): bool
    {
        if ($uid < 1) {
            return false;
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('admin', 'disable', 'deleted')->from('be_users')
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

        return true;
    }
}
