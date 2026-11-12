<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Change;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use TYPO3\CMS\Core\DataHandling\DataHandler;

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

        $action = match ($command) {
            'move'     => ChangeAction::MOVED,
            'delete'   => ChangeAction::DELETED,
            'undelete' => ChangeAction::RESTORED,
            default    => null,
        };
        if ($action instanceof ChangeAction) {
            ToolChangeCollector::record($table, (int) $id, $action, $workspace, $correlation);
        }
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
