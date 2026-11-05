<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The "who changed this record" tool (ADR-046).
 *
 * Renders a record's change history from `sys_history` newest-first: when,
 * who (resolved backend username), what action, and — for modifications —
 * the changed fields as `old → new` value pairs.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): fail-closed
 * without a backend user; the TARGET table passes
 * {@see TableReadAccessService::canReadTable()} (sensitive-table denylist for
 * everyone, `tables_select` for non-admins) with the same neutral denial as
 * the schema tools, and non-admins additionally need PAGE_SHOW on the
 * record's page (same per-row gate as read_records). Values of
 * credential-like fields are never rendered — only the fact that they
 * changed. All values are length-capped.
 *
 * On a workspace-aware table the record is addressed by its live uid, as the
 * run's workspace shows it, for every user: a version row's own uid and a
 * record of another workspace are refused like a missing one. The entries
 * are the ones core's history module shows in that workspace (`sys_history`
 * `workspace` 0 or the run's draft workspace), and in a draft workspace they
 * include the changes of the record's version there (ADR-263).
 */
final readonly class GetRecordHistoryTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use SafeCastTrait;
    use ChecksPageAccessAsActingUserTrait;
    use ReadsTheRunsWorkspaceTrait;

    private const NOT_PERMITTED = 'Table not found or not permitted.';

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 20;

    /** Per-value render cap, keeps one entry roughly one line. */
    private const VALUE_WIDTH = 120;

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_record_history',
            'Show the change history of one record (from sys_history): when, which backend user, and '
            . 'which fields changed with old → new values. Use to answer "who changed X and when".',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The record\'s table (e.g. "tt_content", "pages").',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The record uid.',
                    ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'Optional column name — only show changes touching this field (e.g. "header").',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum history entries (default 10, capped at 20).',
                    ],
                ],
                'required' => ['table', 'uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $table = trim(self::toStr($arguments['table'] ?? ''));
        $uid   = self::toInt($arguments['uid'] ?? 0);
        if ($table === '' || $uid < 1) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $user = $context->actingBackendUser();
        if (!$this->tableAccess->canReadTable($user, $table)) {
            // Same neutral string whether unknown, denylisted or unpermitted.
            return ToolResult::text(self::NOT_PERMITTED);
        }

        // Non-admins must hold PAGE_SHOW on the record's page (same gate as
        // read_records) — history values must not leak from unreadable pages.
        // Fail-closed: an unresolvable record (e.g. hard-deleted) denies too.
        if ($user instanceof BackendUserAuthentication && !$user->isAdmin() && !$this->recordPageIsReadable($user, $table, $uid)) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        // The history of the record as the run's workspace has it (ADR-263).
        $workspace = $this->workspaceToRead($context, $user);
        $records   = $this->historyRecordUids($table, $uid, $workspace);
        if ($records === null) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $fieldFilter = trim(self::toStr($arguments['field'] ?? ''));
        $limit       = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit       = max(1, min(self::MAX_LIMIT, $limit));

        $rows = $this->fetchHistory($table, $records, $limit, $this->isWorkspaceAware($table) ? $workspace : null);
        if ($rows === []) {
            return ToolResult::text(sprintf('No change history for %s:%d.', $table, $uid));
        }

        $usernames = $this->resolveUsernames($rows);

        $entries = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $changes = $this->renderChanges($row, $fieldFilter);
            if ($fieldFilter !== '' && $changes === null) {
                ++$skipped;
                continue;
            }

            $entries[] = sprintf(
                '- %s UTC by %s (%s)%s%s',
                gmdate('Y-m-d H:i', self::toInt($row['tstamp'] ?? 0)),
                $usernames[self::toInt($row['userid'] ?? 0)] ?? '(unknown)',
                $this->actionWord(self::toInt($row['actiontype'] ?? 0)),
                $this->impersonationSuffix($row, $usernames),
                $changes !== null && $changes !== '' ? ': ' . $changes : '',
            );
        }

        if ($entries === []) {
            return ToolResult::text(sprintf(
                'No changes touching "%s" in the last %d history entries of %s:%d.',
                $fieldFilter,
                count($rows),
                $table,
                $uid,
            ));
        }

        $header = sprintf(
            'Change history for %s:%d (%d %s, newest first%s):',
            $table,
            $uid,
            count($entries),
            count($entries) === 1 ? 'entry' : 'entries',
            $fieldFilter !== '' ? sprintf(', filtered to "%s"', $fieldFilter) : '',
        );
        if ($skipped > 0) {
            $entries[] = sprintf('(%d entries not touching "%s" skipped.)', $skipped, $fieldFilter);
        }

        return ToolResult::text($header . "\n" . implode("\n", $entries));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces table read access.
        return false;
    }

    public function getGroup(): string
    {
        return 'content';
    }

    /**
     * True when the record's page passes the acting non-admin's PAGE_SHOW
     * permission ({@see ReadRecordsTool} applies the same per-row gate). The
     * record row is loaded ignoring enable-fields but not soft-delete; a
     * record that cannot be resolved denies (fail-closed).
     */
    private function recordPageIsReadable(BackendUserAuthentication $user, string $table, int $uid): bool
    {
        $row = BackendUtility::getRecord($table, $uid, 'uid,pid');
        if (!is_array($row)) {
            return false;
        }

        $pageUid = $table === 'pages' ? self::toInt($row['uid'] ?? 0) : self::toInt($row['pid'] ?? 0);
        if ($pageUid < 1) {
            return false;
        }

        $permsClause = self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));

        return $this->actingUserMayAccessPage($user, $pageUid, $permsClause);
    }

    /**
     * The `recuid`s whose history is the record's in `$workspace`, or null
     * where the uid names no record there.
     *
     * A table without workspaces: the uid. A workspace-aware one: refused
     * where a row with that uid exists but is a version row or a record of
     * another workspace — its history is that workspace's draft — and, in a
     * draft workspace, the uid plus its version's there, whose `recuid` core
     * logs the workspace's changes under. A uid with no row at all (a
     * hard-deleted record) keeps its own history.
     *
     * @return list<int>|null
     */
    private function historyRecordUids(string $table, int $uid, int $workspace): ?array
    {
        if (!$this->isWorkspaceAware($table)) {
            return [$uid];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('t3ver_oid', 't3ver_wsid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($row)
            && (self::toInt($row['t3ver_oid'] ?? 0) !== 0 || !in_array(self::toInt($row['t3ver_wsid'] ?? 0), [0, $workspace], true))
        ) {
            return null;
        }

        $version = $workspace > 0 ? BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, $uid, 'uid') : false;
        $records = [$uid];
        if (is_array($version) && self::toInt($version['uid'] ?? 0) !== $uid) {
            $records[] = self::toInt($version['uid'] ?? 0);
        }

        return $records;
    }

    /**
     * @param list<int> $records   the `recuid`s of the record
     * @param int|null  $workspace the entries' workspace for a workspace-aware table (0 or the run's draft
     *                             workspace, as core's history module filters them); null for none
     *
     * @return list<array<string, mixed>>
     */
    private function fetchHistory(string $table, array $records, int $limit, ?int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');

        $queryBuilder
            ->select('tstamp', 'actiontype', 'userid', 'originaluserid', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($records, Connection::PARAM_INT_ARRAY)),
            );

        if ($workspace !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                'workspace',
                $queryBuilder->createNamedParameter(array_values(array_unique([0, $workspace])), Connection::PARAM_INT_ARRAY),
            ));
        }

        $rows = $queryBuilder
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * One batched uid → username lookup for all user ids in the result set.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function resolveUsernames(array $rows): array
    {
        $uids = [];
        foreach ($rows as $row) {
            foreach (['userid', 'originaluserid'] as $key) {
                $userUid = self::toInt($row[$key] ?? 0);
                if ($userUid > 0) {
                    $uids[$userUid] = true;
                }
            }
        }

        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        // History must name authors even when their account is meanwhile
        // disabled or deleted — only the username egresses, nothing else.
        $queryBuilder->getRestrictions()->removeAll();

        $userRows = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(array_keys($uids), Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($userRows as $userRow) {
            $map[self::toInt($userRow['uid'] ?? 0)] = self::toStr($userRow['username'] ?? '');
        }

        return $map;
    }

    /**
     * The changed fields of one history row as `field: 'old' → 'new'` pairs.
     * Null when a field filter is set and the row does not touch it.
     *
     * @param array<string, mixed> $row
     */
    private function renderChanges(array $row, string $fieldFilter): ?string
    {
        $data = json_decode(self::toStr($row['history_data'] ?? ''), true);
        if (!is_array($data)) {
            return $fieldFilter === '' ? '' : null;
        }

        $old = is_array($data['oldRecord'] ?? null) ? $data['oldRecord'] : [];
        $new = is_array($data['newRecord'] ?? null) ? $data['newRecord'] : [];
        if ($old === [] && $new === []) {
            return $fieldFilter === '' ? '' : null;
        }

        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $parts  = [];
        foreach ($fields as $field) {
            $fieldName = (string) $field;
            if ($fieldFilter !== '' && $fieldName !== $fieldFilter) {
                continue;
            }

            // Credential-like fields: the change is visible, the values are not.
            if ($this->tableAccess->isSensitiveField($fieldName)) {
                $parts[] = sprintf('%s: [changed — detail withheld]', $fieldName);
                continue;
            }

            $parts[] = sprintf(
                "%s: '%s' → '%s'",
                $fieldName,
                $this->renderValue($old[$fieldName] ?? null),
                $this->renderValue($new[$fieldName] ?? null),
            );
        }

        if ($parts === []) {
            return $fieldFilter === '' ? '' : null;
        }

        return implode('; ', $parts);
    }

    private function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '(unset)';
        }

        if (!is_scalar($value)) {
            return '[complex value]';
        }

        $text = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        return mb_strimwidth($text, 0, self::VALUE_WIDTH, '…');
    }

    private function actionWord(int $actionType): string
    {
        return match ($actionType) {
            RecordHistoryStore::ACTION_ADD      => 'add',
            RecordHistoryStore::ACTION_MODIFY   => 'modify',
            RecordHistoryStore::ACTION_MOVE     => 'move',
            RecordHistoryStore::ACTION_DELETE   => 'delete',
            RecordHistoryStore::ACTION_UNDELETE => 'undelete',
            default                             => sprintf('action #%d', $actionType),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $usernames
     */
    private function impersonationSuffix(array $row, array $usernames): string
    {
        $original = self::toInt($row['originaluserid'] ?? 0);
        if ($original < 1 || $original === self::toInt($row['userid'] ?? 0)) {
            return '';
        }

        return sprintf(' via impersonation by %s', $usernames[$original] ?? '(unknown)');
    }
}
