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
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aResumeGuard implements RunResumeGuardInterface
{
    public function __construct(
        private A2aInboundClientRepository $clients,
        private A2aSkillProfileRepository $profiles,
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
