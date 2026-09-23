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
 * - `$complain` adds an entry to the DataHandler's error log while the update
 *   is still written, as a hook that logs and carries on does.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again in the test's tearDown.
 */
final class InterferesWithAnUpdateHook
{
    public static bool $keepVisible = false;

    public static ?string $dropColumn = null;

    public static bool $complain = false;

    public static function reset(): void
    {
        self::$keepVisible = false;
        self::$dropColumn  = null;
        self::$complain    = false;
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
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

        if (self::$complain) {
            $dataHandler->log($table, (int)$id, 2, null, 1, 'A test hook complains and carries on');
        }
    }
}
