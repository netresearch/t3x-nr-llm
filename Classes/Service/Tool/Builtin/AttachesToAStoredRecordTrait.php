<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The question the two attach writers ask of the record a file reference
 * goes onto before the approval card (ADR-264): the one the DataHandler asks
 * before it updates that record's file field.
 *
 * The consuming class must use {@see CreatesRecordsInTheRunsWorkspaceTrait}
 * and {@see ActsOnAnExistingRecordTrait}.
 */
trait AttachesToAStoredRecordTrait
{
    /**
     * Whether the user may change the record its STORED row is — the
     * question the DataHandler asks before it updates a record
     * (`process_datamap()`: `BackendUtility::getRecord()` of the uid, then
     * `hasPermissionToUpdate()` on that row's page and `checkRecordEditAccess()`
     * on the row, 13.4 and 14.3).
     *
     * The page is the one the stored row sits on: for a record moved in the
     * draft workspace that is the page it left, while the reference it gets
     * is inserted on the page it moved to, which the caller asks. The
     * record-level rights — language, `authMode` values such as the content
     * type, edit lock — are asked through
     * {@see ActsOnAnExistingRecordTrait::mayEditRecord()}: where core refuses
     * the update after the reference row was written, the version this call
     * made cannot be taken back by the same user either, because core asks
     * the discard the same question (ADR-264).
     *
     * @param non-empty-string $table
     */
    private function mayUpdateStoredRecord(string $table, int $uid, BackendUserAuthentication $user): bool
    {
        $stored = $this->storedRowIn($table, $uid, $user->workspace);
        if ($stored === null || !$this->mayEditRecord($table, $stored, $user)) {
            return false;
        }

        $pid = self::toInt($stored['pid'] ?? 0);

        return $pid === 0 ? $user->isAdmin() : $this->pageForWriteIn($pid, $user, Permission::CONTENT_EDIT) !== null;
    }
}
