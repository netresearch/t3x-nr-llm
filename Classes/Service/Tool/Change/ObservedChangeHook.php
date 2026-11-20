<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Change;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Feeds {@see ToolChangeCollector} from every DataHandler run (ADR-292).
 *
 * Global hooks run for the nested DataHandler instances core creates for a
 * copy or a translation as well, so the records written along with the asked
 * one reach the collector too. The hook stores identities and the workspace of
 * the user who wrote, never a field value, and does nothing while no tool call
 * is executing.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ObservedChangeHook
{
    /**
     * Before the first record of a datamap run is written: the point where the
     * collector notes how far `sys_history` has come. It reads none of the
     * arguments the DataHandler passes.
     */
    public function processDatamap_preProcessFieldArray(): void
    {
        ToolChangeCollector::beforeWrite();
    }

    /**
     * Before a command is carried out: the same point for the command map.
     */
    public function processCmdmap_preProcess(): void
    {
        ToolChangeCollector::beforeWrite();
    }

    /**
     * @param string                    $status     'new' or 'update'
     * @param int|string                $id         the uid, or the NEW… placeholder of a created record
     * @param array<string, mixed>|null $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, ?array $fieldArray, DataHandler $dataHandler): void
    {
        if (!ToolChangeCollector::isArmed()) {
            return;
        }

        $uid = $status === 'new' ? ($dataHandler->substNEWwithIDs[$id] ?? null) : $id;
        if (!is_numeric($uid)) {
            return;
        }

        ToolChangeCollector::record(
            $table,
            (int) $uid,
            $status === 'new' ? ChangeAction::CREATED : ChangeAction::UPDATED,
            $this->workspaceOf($dataHandler),
            $this->scopeOf($dataHandler),
        );
    }

    /**
     * @param int|string $id
     */
    public function processCmdmap_postProcess(string $command, string $table, $id, mixed $value, DataHandler $dataHandler, mixed $pasteUpdate = null, mixed $pasteDatamap = null): void
    {
        if (!ToolChangeCollector::isArmed() || !is_numeric($id)) {
            return;
        }

        $workspace   = $this->workspaceOf($dataHandler);
        $correlation = $this->scopeOf($dataHandler);

        if ($command === 'copy') {
            // Every record this command copied, children included: core copies
            // them without a datamap run of their own.
            foreach ($dataHandler->copyMappingArray as $copiedTable => $copies) {
                foreach ($copies as $copyUid) {
                    if (is_numeric($copyUid)) {
                        ToolChangeCollector::record((string) $copiedTable, (int) $copyUid, ChangeAction::CREATED, $workspace, $correlation);
                    }
                }
            }

            return;
        }

        // A publish writes the live row the command names, or makes a new
        // record's draft its live row, and a stage change writes the draft
        // (#1442). A discard is a hard delete the history names. A new version
        // is named by the history and by the datamap entry that asked for it:
        // core versions a live row through this command for every first edit
        // in a draft, which names the live row, not the version.
        $version = $command === 'version' && is_array($value) ? ($value['action'] ?? null) : null;
        if ($version === 'swap' || $version === 'publish') {
            if ($this->wasPublished($table, (int) $id, is_numeric($value['swapWith'] ?? null) ? (int) $value['swapWith'] : 0)) {
                ToolChangeCollector::record($table, (int) $id, ChangeAction::UPDATED, 0, $correlation);
            }

            return;
        }

        $action = match ($command) {
            'move'     => ChangeAction::MOVED,
            'delete'   => ChangeAction::DELETED,
            'undelete' => ChangeAction::RESTORED,
            default    => $version === 'setStage' ? ChangeAction::UPDATED : null,
        };
        if ($action instanceof ChangeAction) {
            ToolChangeCollector::record($table, (int) $id, $action, $workspace, $correlation);
        }
    }

    /**
     * Whether a publish took place: core deletes the draft it published into
     * the live row, and a new record's draft becomes the live row. A publish
     * core refused (no access, the wrong stage, a draft of another record)
     * leaves both as they were. A read that fails counts as published, so the
     * record is listed and judged.
     */
    private function wasPublished(string $table, int $id, int $swapWith): bool
    {
        // @phpstan-ignore-next-line TCA access returns mixed at each nesting level
        if (!(bool) ($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false)) {
            return false;
        }

        try {
            $workspace = $this->stored($table, $id);
            if ($workspace !== 0) {
                return false;
            }

            return $swapWith < 1 || $swapWith === $id || $this->stored($table, $swapWith) === null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The `t3ver_wsid` of a row, deleted ones included, or null where no row
     * bears the uid.
     */
    private function stored(string $table, int $uid): ?int
    {
        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $value = $query
            ->select('t3ver_wsid')
            ->from($table)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The scope the chain's history rows share. Not the id's string form: an id
     * without a subject, which is what a chain carries until a row is written,
     * refuses to be turned into one.
     */
    private function scopeOf(DataHandler $dataHandler): ?string
    {
        $scope = $dataHandler->getCorrelationId()?->getScope();

        return $scope === null || $scope === '' ? null : $scope;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function workspaceOf(DataHandler $dataHandler): int
    {
        return max(0, $this->int($dataHandler->BE_USER->workspace ?? 0));
    }
}
