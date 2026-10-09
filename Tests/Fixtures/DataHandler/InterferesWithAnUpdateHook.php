<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that interferes with an UPDATE of `pages` or
 * `tt_content`, the way an installation's own hook can: after every check,
 * without an error of the kind the writers pre-check (ADR-198).
 *
 * What it does is chosen per test through the static switches below, and the
 * test resets them in tearDown:
 *
 * - `$keepVisible` turns every `hidden = 1` back to 0 — on the row core
 *   creates for a copy, on the paste update that hides it and on the second
 *   write that hides its translations alike, so `copy_record` must take the
 *   copy back;
 * - `$dropColumn` removes that column from every update, as a missing grant
 *   would, while the rest of the update is written;
 * - `$dropColumnOnCreate` removes that column from a NEW row of any table, as
 *   a hook that empties a field on create would — the one switch that is not
 *   limited to `pages` and `tt_content`;
 * - `$complain` adds an entry to the DataHandler's error log while the update
 *   is still written, as a hook that logs and carries on does — on every
 *   update, or with `$complainWithField` only on one that writes that field;
 * - `$complainOnCommand` does the same after every command (a move, a
 *   delete) of the cmdmap, which still runs;
 * - `$keepRecord` names one `table:uid` whose delete the hook takes over and
 *   does not carry out, as an installation's hook that vetoes a delete can —
 *   the record stays live while the rest of the command runs;
 * - `$keepInPlace` names one `table:uid` whose move the hook takes over and
 *   does not carry out, so a page translation stays where it was while its
 *   default-language page moves.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']`
 * under `processDatamapClass`, `processCmdmapClass` and `moveRecordClass`,
 * and removed again in the test's tearDown.
 */
final class InterferesWithAnUpdateHook
{
    public static bool $keepVisible = false;

    public static ?string $dropColumn = null;

    /** A column dropped from a NEW row of any table, as a hook that empties a field on create would. */
    public static ?string $dropColumnOnCreate = null;

    public static bool $complain = false;

    public static ?string $complainWithField = null;

    public static bool $complainOnCommand = false;

    public static ?string $keepRecord = null;

    public static ?string $keepInPlace = null;

    public static function reset(): void
    {
        self::$keepVisible       = false;
        self::$dropColumn         = null;
        self::$dropColumnOnCreate = null;
        self::$complain          = false;
        self::$complainWithField = null;
        self::$complainOnCommand = false;
        self::$keepRecord        = null;
        self::$keepInPlace       = null;
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($status === 'new' && self::$dropColumnOnCreate !== null) {
            unset($fieldArray[self::$dropColumnOnCreate]);
        }

        if (!in_array($table, ['pages', 'tt_content'], true)) {
            return;
        }

        // On the new row too: core writes a copy hidden already
        // (`hideAtCopy`), and an update that sets an unchanged value never
        // reaches this hook.
        if (self::$keepVisible && in_array($fieldArray['hidden'] ?? null, [1, '1'], true)) {
            $fieldArray['hidden'] = 0;
        }

        if ($status !== 'update') {
            return;
        }

        if (self::$dropColumn !== null) {
            unset($fieldArray[self::$dropColumn]);
        }

        if (self::$complain && (self::$complainWithField === null || array_key_exists(self::$complainWithField, $fieldArray))) {
            $dataHandler->log($table, (int)$id, 2, null, 1, 'A test hook complains and carries on');
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        if (self::$complainOnCommand) {
            $dataHandler->log($table, (int)$id, 2, null, 1, 'A test hook complains about a command and carries on');
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function processCmdmap_deleteAction(string $table, string|int $id, array $record, bool &$recordWasDeleted, DataHandler $dataHandler): void
    {
        if (self::$keepRecord === $table . ':' . $id) {
            $recordWasDeleted = true;
        }
    }

    /**
     * @param array<string, mixed> $propArr
     * @param array<string, mixed> $moveRec
     */
    public function moveRecord(string $table, string|int $uid, mixed $destPid, array $propArr, array $moveRec, mixed $resolvedPid, bool &$recordWasMoved, DataHandler $dataHandler): void
    {
        if (self::$keepInPlace === $table . ':' . $uid) {
            $recordWasMoved = true;
        }
    }
}
