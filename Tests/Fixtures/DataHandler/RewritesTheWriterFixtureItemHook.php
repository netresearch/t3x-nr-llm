<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that rewrites a NEW `tx_writerfixture_item` row after
 * every check and before it is inserted, chosen by the row's title.
 *
 * It stands in for whatever rewrites a record in an installation without an
 * error — an installation's own `processDatamap_postProcessFieldArray` can
 * change any field after the DataHandler validated it and reports nothing —
 * so a test of create_record_draft's read-back needs a producer no pre-check
 * of the tool can see (ADR-197):
 *
 * - title `rewrite:hidden` sets the hidden flag to 0,
 * - title `rewrite:pid` moves the row to page 1,
 * - title `rewrite:teaser` replaces the teaser,
 * - title `rewrite:language` moves the row to language 1,
 * - title `rewrite:type` changes the record type to `story`.
 *
 * Every other row, and the delete that takes a failed write back, is left
 * alone. Stateless, so no test leaks into the next; registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again in the test's tearDown.
 */
final class RewritesTheWriterFixtureItemHook
{
    public const TABLE = 'tx_writerfixture_item';

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'new' || $table !== self::TABLE) {
            return;
        }

        match ($fieldArray['title'] ?? null) {
            'rewrite:hidden'   => $fieldArray['hidden'] = 0,
            'rewrite:pid'      => $fieldArray['pid'] = 1,
            'rewrite:teaser'   => $fieldArray['teaser'] = 'Rewritten by a hook',
            'rewrite:language' => $fieldArray['sys_language_uid'] = 1,
            'rewrite:type'     => $fieldArray['kind'] = 'story',
            default            => null,
        };
    }
}
