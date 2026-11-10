<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\GroupResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves whose AI usage a backend user may see in the group usage view
 * (ADR-277).
 *
 * - An administrator sees everyone.
 * - A user without the `usage_view_groups` grant sees nobody and is denied.
 * - A holder sees the members of the groups assigned to him: the union of
 *   `be_groups.tx_nrllm_usage_visible_groups` over every group he resolves
 *   to (his own groups and the subgroups they include, hidden groups left
 *   out by core) — the same way the grant itself is merged.
 *
 * Membership is TYPO3's: a user is a member of an assigned group G when G is
 * one of his groups or one of his groups includes G as a subgroup,
 * recursively — core's {@see GroupResolver::findAllUsersInGroups()} with its
 * default restrictions, which leave out disabled, deleted and expired users
 * and hidden or deleted groups. A user who is only in a subgroup OF G is not
 * a member of G. Administrators and command-line users (`_cli_*`) are never
 * in the audience, as in {@see EditorEntitlementDirectory}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class UsageAudienceResolver
{
    public const ASSIGNMENT_FIELD = 'tx_nrllm_usage_visible_groups';

    public function __construct(
        private ConnectionPool $connectionPool,
        private GroupResolver $groupResolver,
    ) {}

    public function forUser(?BackendUserAuthentication $user): UsageAudience
    {
        if (!$user instanceof BackendUserAuthentication || !is_array($user->user)) {
            return UsageAudience::denied();
        }

        if ($user->isAdmin()) {
            return UsageAudience::everyone();
        }

        if (!$user->check('custom_options', BackendUserGrant::USAGE_VIEW_GROUPS->permissionValue())) {
            return UsageAudience::denied();
        }

        $assigned = $this->assignedGroups($this->assignedGroupUids($user));
        if ($assigned === []) {
            return UsageAudience::members([], []);
        }

        $members = [];
        foreach ($this->groupResolver->findAllUsersInGroups(array_column($assigned, 'uid'), 'be_groups', 'be_users') as $member) {
            $uid = $this->memberUid($member);
            if ($uid !== null) {
                $members[] = $uid;
            }
        }

        return UsageAudience::members($members, $assigned);
    }

    /**
     * @return list<int>
     */
    private function assignedGroupUids(BackendUserAuthentication $user): array
    {
        $uids = [];
        foreach ($user->userGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $value = $group[self::ASSIGNMENT_FIELD] ?? '';
            foreach (GeneralUtility::intExplode(',', is_string($value) ? $value : '', true) as $uid) {
                if ($uid > 0) {
                    $uids[$uid] = $uid;
                }
            }
        }

        return array_values($uids);
    }

    /**
     * The assigned groups that exist and are not hidden, by title — the same
     * groups core resolves members for.
     *
     * @param list<int> $uids
     *
     * @return list<array{uid: int, title: string}>
     */
    private function assignedGroups(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $qb   = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $rows = $qb
            ->select('uid', 'title')
            ->from('be_groups')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $groups = [];
        foreach ($rows as $row) {
            if (is_numeric($row['uid'] ?? null)) {
                $groups[] = ['uid' => (int) $row['uid'], 'title' => is_string($row['title'] ?? null) ? $row['title'] : ''];
            }
        }

        return $groups;
    }

    private function memberUid(mixed $member): ?int
    {
        if (!is_array($member) || !is_numeric($member['uid'] ?? null)) {
            return null;
        }

        if ((bool) ($member['admin'] ?? false)) {
            return null;
        }

        $username = $member['username'] ?? '';
        if (is_string($username) && str_starts_with($username, EditorEntitlementDirectory::CLI_USERNAME_PREFIX)) {
            return null;
        }

        return (int) $member['uid'];
    }
}
