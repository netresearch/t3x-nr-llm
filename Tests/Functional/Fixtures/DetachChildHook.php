<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A DataHandler hook that detaches every new `tx_childrenfixture_item` row
 * from its element once the datamap is written, as a foreign hook may: a
 * take-back that deletes the element alone leaves such a child behind
 * (ADR-324, spec CC9).
 */
final class DetachChildHook
{
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->substNEWwithIDs as $placeholder => $uid) {
            if (($dataHandler->substNEWwithIDs_table[$placeholder] ?? '') === 'tx_childrenfixture_item') {
                (new ConnectionPool())->getConnectionForTable('tx_childrenfixture_item')
                    ->update('tx_childrenfixture_item', ['parent_content' => 0], ['uid' => (int) $uid]);
            }
        }
    }
}
