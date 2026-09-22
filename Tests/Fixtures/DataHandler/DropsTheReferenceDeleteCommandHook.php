<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that blanks the FIRST `delete` command on
 * `sys_file_reference` in a run that also creates a `sys_file_reference`, so
 * the DataHandler performs nothing for it and reports nothing about it. With
 * one replaced reference that is the whole delete; with several, the rest go
 * through — which is what a partly removed list looks like.
 *
 * It stands in for whatever keeps a replaced reference alive in an
 * installation without an error: the tool's own cmdmap delete is checked
 * against the same page right the datamap just passed, so a test of the
 * "previous reference was not removed" backstop needs a producer the
 * permission checks cannot see. `processCmdmap_preProcess` receives the
 * command by reference, and a command the DataHandler does not know is one it
 * skips.
 *
 * Only the run that creates the reference is touched. The run that takes a
 * failed write back deletes the new reference through a cmdmap of its own,
 * with the page row alone in its datamap, and that delete has to happen.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']`
 * and removed again in the test's tearDown. The DataHandler instantiates its
 * hooks afresh for every `process_cmdmap()`, so "first" is per run and no
 * test leaks into the next.
 */
final class DropsTheReferenceDeleteCommandHook
{
    private bool $dropped = false;

    public function processCmdmap_preProcess(string &$command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        if ($this->dropped || $table !== 'sys_file_reference' || $command !== 'delete' || !isset($dataHandler->datamap['sys_file_reference'])) {
            return;
        }

        $command       = '';
        $this->dropped = true;
    }
}
