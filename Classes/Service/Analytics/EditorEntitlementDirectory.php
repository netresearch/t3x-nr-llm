<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use TYPO3\CMS\Core\Authentication\GroupResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Which backend users may use the AI features — the "entitled editors" of
 * the billing report — and the account state of the users that did.
 *
 * Entitled is the state an editor needs to run an AI task (ADR-130/131):
 *
 * - the `tasks_use` grant ({@see BackendUserGrant::TASKS_USE}) through one
 *   of the user's backend groups, a subgroup of one included — the grant is
 *   a `be_groups.custom_options` value, so a user without groups holds none;
 * - access to the `nrllm_aitasks` module, ticked on the user record or on one
 *   of the user's groups, a subgroup included (the grant alone opens no
 *   page: ADR-131);
 * - an account that can log in now: not deleted, not disabled, inside its
 *   start and end time. Hidden and deleted groups grant nothing (`be_groups`
 *   has no start or end time).
 *
 * Administrators are not counted: they hold every grant implicitly and are
 * not the editors the report bills. The page states their number separately.
 *
 * Users, groups and their permissions keep no history, so the answer is the
 * state at the moment of the query, whatever month the report shows.
 *
 * Both the grant and the module are resolved bottom-up the same way: the
 * groups that carry them, then every user in such a group or in a group that
 * includes one as a subgroup, through core's
 * {@see GroupResolver::findAllUsersInGroups()} with its default restrictions.
 * The number of queries does not grow with the number of users.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class EditorEntitlementDirectory
{
    public const EDITOR_MODULE = 'nrllm_aitasks';

    /**
     * Core's command-line user is `_cli_`; older versions also created
     * `_cli_*` users. Core creates it as an administrator with a random
     * password, and console commands (the scheduler included) run as it.
     */
    public const CLI_USERNAME_PREFIX = '_cli_';

    public function __construct(
        private ConnectionPool $connectionPool,
        private GroupResolver $groupResolver,
    ) {}

    /**
     * @return list<array{uid: int, username: string, realName: string}> ordered by username
     */
    public function entitledEditors(): array
    {
        $grantingGroups = $this->groupsWhere('custom_options', BackendUserGrant::TASKS_USE->permissionValue());
        if ($grantingGroups === []) {
            return [];
        }

        // Every enabled user in a granting group or in a group that includes
        // one as a subgroup; core's default restrictions drop deleted,
        // disabled and expired users and hidden or deleted groups.
        $candidates = $this->groupResolver->findAllUsersInGroups($grantingGroups, 'be_groups', 'be_users');
        $withModule = $this->usersWithEditorModule();

        $editors = [];
        foreach ($candidates as $user) {
            $editor = $this->entitledEditor($user, $withModule);
            if ($editor !== null) {
                $editors[$editor['uid']] = $editor;
            }
        }

        usort($editors, static fn (array $a, array $b): int => strcasecmp($a['username'], $b['username']) ?: $a['uid'] <=> $b['uid']);

        return $editors;
    }

    /**
     * A grant holder as a list entry, or null when the user is an
     * administrator or lacks the editor module.
     *
     * @param array<int, true> $withModule
     *
     * @return array{uid: int, username: string, realName: string}|null
     */
    private function entitledEditor(mixed $user, array $withModule): ?array
    {
        if (!is_array($user) || $this->flag($user['admin'] ?? null)) {
            return null;
        }

        $uid = is_numeric($user['uid'] ?? null) ? (int) $user['uid'] : 0;
        if ($uid <= 0 || !isset($withModule[$uid])) {
            return null;
        }

        return [
            'uid'      => $uid,
            'username' => is_string($user['username'] ?? null) ? $user['username'] : '',
            'realName' => is_string($user['realName'] ?? null) ? $user['realName'] : '',
        ];
    }

    /**
     * Administrators who can log in now — stated next to the entitled count
     * because they can use every AI feature without being counted in it.
     *
     * Not counted: core's command-line user `_cli_` (and the `_cli_*` users
     * of older versions). Core creates it as an administrator with a random
     * password the first time a console command runs; nobody logs in with it.
     */
    public function activeAdministratorCount(): int
    {
        $qb    = $this->connectionPool->getQueryBuilderForTable('be_users');
        $count = $qb
            ->count('uid')
            ->from('be_users')
            ->where(
                $qb->expr()->eq('admin', $qb->createNamedParameter(1, Connection::PARAM_INT)),
                $qb->expr()->notLike('username', $qb->createNamedParameter($qb->escapeLikeWildcards(self::CLI_USERNAME_PREFIX) . '%')),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Account state of the given users, read without restrictions so that a
     * deleted or disabled user who made requests in the month is still
     * listed under the name they had.
     *
     * @param list<int> $uids
     *
     * @return array<int, array{username: string, realName: string, admin: bool, disabled: bool, deleted: bool}>
     */
    public function accountStates(array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('uid', 'username', 'realName', 'admin', 'disable', 'deleted')
            ->from('be_users')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $states = [];
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            if ($uid <= 0) {
                continue;
            }

            $states[$uid] = [
                'username' => is_string($row['username'] ?? null) ? $row['username'] : '',
                'realName' => is_string($row['realName'] ?? null) ? $row['realName'] : '',
                'admin'    => $this->flag($row['admin'] ?? null),
                'disabled' => $this->flag($row['disable'] ?? null),
                'deleted'  => $this->flag($row['deleted'] ?? null),
            ];
        }

        return $states;
    }

    /**
     * Enabled groups whose comma-separated $field lists $value.
     *
     * @return list<int>
     */
    private function groupsWhere(string $field, string $value): array
    {
        $qb   = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $rows = $qb
            ->select('uid')
            ->from('be_groups')
            ->where($qb->expr()->inSet($field, $qb->quote($value)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $rows));
    }

    /**
     * Module access as core merges it — the user's own `userMods` plus the
     * `groupMods` of every group the user resolves to, subgroups included —
     * resolved from the module's side: users in a group that lists the
     * module (or in a group including one), and users who list it themselves.
     *
     * @return array<int, true> keyed by uid
     */
    private function usersWithEditorModule(): array
    {
        $uids = [];

        $moduleGroups = $this->groupsWhere('groupMods', self::EDITOR_MODULE);
        if ($moduleGroups !== []) {
            foreach ($this->groupResolver->findAllUsersInGroups($moduleGroups, 'be_groups', 'be_users') as $user) {
                if (is_array($user) && is_numeric($user['uid'] ?? null)) {
                    $uids[(int) $user['uid']] = true;
                }
            }
        }

        $qb   = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $qb
            ->select('uid')
            ->from('be_users')
            ->where($qb->expr()->inSet('userMods', $qb->quote(self::EDITOR_MODULE)))
            ->executeQuery()
            ->fetchFirstColumn();
        foreach ($rows as $uid) {
            if (is_numeric($uid)) {
                $uids[(int) $uid] = true;
            }
        }

        return $uids;
    }

    private function flag(mixed $value): bool
    {
        return is_numeric($value) && (int) $value === 1;
    }
}
