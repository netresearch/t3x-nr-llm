<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only write path for the tool audit trail (`tx_nrllm_tool_audit`).
 *
 * Follows the skill audit trail (ADR-061, {@see \Netresearch\NrLlm\Service\Skill\SkillAuditRepository}):
 * {@see record()} inserts an immutable row and there is no update path; the
 * one delete path is the retention purge, {@see purgeOlderThan()}, driven by
 * `nrllm:privacy:purge`. A row holds names, two flags and backend user uids,
 * no free-form content, so nothing passes the content privacy filter.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ToolAuditRepository implements ToolAuditRepositoryInterface
{
    private const TABLE = 'tx_nrllm_tool_audit';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function record(ToolAuditScope $scope, string $name, bool $previous, bool $enabled, ?BackendUserAuthentication $actor): void
    {
        $user = is_array($actor?->user) ? $actor->user : [];
        $uid  = $user['uid'] ?? 0;

        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'         => 0,
            'crdate'      => time(),
            'scope'       => $scope->value,
            'name'        => $name,
            'old_enabled' => $previous ? 1 : 0,
            'new_enabled' => $enabled ? 1 : 0,
            'actor_uid'   => is_numeric($uid) ? (int) $uid : 0,
            // In switch-user mode the session user is the one switched to;
            // the administrator who switched is the one who acted.
            'original_actor_uid' => $actor?->getOriginalUserIdWhenInSwitchUserMode() ?? 0,
        ]);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }
}
