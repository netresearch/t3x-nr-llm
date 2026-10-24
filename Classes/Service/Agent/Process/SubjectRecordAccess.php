<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Whether the backend user a run executes as may read the record its process
 * is about (ADR-214 item 10, ADR-228).
 *
 * Checked at start, at worker pickup and at every continuation, against the
 * live user the run's tools act as — not the ambient `$GLOBALS['BE_USER']`,
 * which a queue worker does not have. Fail-closed on every gate the record
 * tools apply: no user, a table the user may not select or the denylist
 * holds, a language the user may not edit, a record that does not exist, is
 * deleted or is not live (a workspace version or placeholder), a translation
 * named under another language than its own, and a page
 * outside the user's mounts or without show permission. Hidden records count
 * as existing: an editor works on hidden pages.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SubjectRecordAccess
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableAccess,
    ) {}

    public function isReadable(?BackendUserAuthentication $user, SubjectRecord $subject): bool
    {
        $table = $subject->record->table;
        if (!$user instanceof BackendUserAuthentication || !$this->tableAccess->canReadTable($user, $table)) {
            return false;
        }

        if (!$user->isAdmin() && !$user->checkLanguageAccess($subject->language)) {
            return false;
        }

        $languageField = $this->languageField($table);
        $row           = $this->fetch($table, $subject->record->uid, $languageField === null ? ['uid', 'pid'] : ['uid', 'pid', $languageField]);
        if ($row === null) {
            return false;
        }

        // The language the record is in, not only the one the caller named.
        // A translation row is the subject only in its own language, so a
        // caller cannot name it under a language the user may edit. A row in
        // the default language or in all languages may be viewed in any
        // language the user may edit — a page worked on in its translation
        // is the default-language page with the subject's language.
        $recordLanguage = $languageField === null ? 0 : $this->toInt($row[$languageField] ?? 0);
        if ($recordLanguage > 0 && $recordLanguage !== $subject->language) {
            return false;
        }

        $pageUid = $table === 'pages' ? $subject->record->uid : $this->toInt($row['pid'] ?? 0);
        $page    = $pageUid > 0 ? $this->fetch('pages', $pageUid, ['*']) : null;
        if ($page === null) {
            // A record on the root level (pid 0) has no page to check; only an
            // administrator reads those, as the backend's list module has it.
            return $pageUid === 0 && $user->isAdmin();
        }

        if ($user->isAdmin()) {
            return true;
        }

        // calcPerms() behind doesUserHaveAccess() answers "nothing" outside
        // the user's web mounts (TYPO3 13.4 and 14.3), so the page-show bit
        // covers the mount as well.
        return $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $table, int $uid, array $fields): ?array
    {
        // Live rows only: the acting user a run resolves works in no
        // workspace, so a workspace version or placeholder is not there for
        // it. Deleted rows are not; hidden and time-restricted rows are.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function languageField(string $table): ?string
    {
        $allTca = $GLOBALS['TCA'] ?? null;
        $tca    = is_array($allTca) && is_array($allTca[$table] ?? null) ? $allTca[$table] : [];
        $ctrl   = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $field  = $ctrl['languageField'] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
