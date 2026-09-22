<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that strips `og_image` and `twitter_image` from every
 * incoming `pages` row of a run that also creates a `sys_file_reference`,
 * before the DataHandler evaluates it.
 *
 * It stands in for whatever drops the page's side of the relation in an
 * installation without an error — the two shapes the tool's pre-check knows
 * are refused before the write, so a test of the read-back backstop needs a
 * producer the pre-check cannot see. A hook is one such producer: an
 * installation's own `processDatamap_preProcessFieldArray` can rewrite any
 * incoming field, and the DataHandler reports nothing about it.
 *
 * Only the run that creates the reference is touched. The run that takes a
 * failed write back carries the page row alone, and it has to reach the page:
 * a hook that dropped the field there too would leave the page's counter
 * untouched whether or not the tool wrote it back, and the test could not
 * tell the two apart.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again in the test's tearDown. Stateless, so no test leaks into
 * the next.
 */
final class DropsThePageImageFieldsHook
{
    /**
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table, string|int $id, DataHandler $dataHandler): void
    {
        if ($table !== 'pages' || !isset($dataHandler->datamap['sys_file_reference'])) {
            return;
        }

        unset($incomingFieldArray['og_image'], $incomingFieldArray['twitter_image']);
    }
}
