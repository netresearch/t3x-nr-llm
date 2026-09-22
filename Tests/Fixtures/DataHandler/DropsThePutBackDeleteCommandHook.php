<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that blanks every `delete` command on
 * `sys_file_reference` in a run that creates NO `sys_file_reference` — the
 * run that takes a failed write back — so the DataHandler performs nothing
 * for it and reports nothing about it.
 *
 * The mirror of {@see DropsTheReferenceDeleteCommandHook}, which touches only
 * the run that creates the reference. This one leaves that run alone and
 * stands in for whatever keeps the put-back from landing in an installation
 * without an error, so a test can hold the tool to reporting a put-back that
 * did not land rather than a page "left as it was".
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']`
 * and removed again in the test's tearDown. Stateless, so no test leaks into
 * the next.
 */
final class DropsThePutBackDeleteCommandHook
{
    public function processCmdmap_preProcess(string &$command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        if ($table !== 'sys_file_reference' || $command !== 'delete' || isset($dataHandler->datamap['sys_file_reference'])) {
            return;
        }

        $command = '';
    }
}
