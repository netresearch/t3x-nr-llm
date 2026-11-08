<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

final class DiscardAiLabelDraftHook implements SingletonInterface
{
    public static int $uid = 0;

    /**
     * @param array<string, mixed> $fields
     */
    public function processDatamap_preProcessFieldArray(
        array &$fields,
        string $table,
    ): void {
        if (self::$uid <= 0 || $table !== 'tt_content' || !array_key_exists('tx_ailabel_metadata', $fields)) {
            return;
        }

        $this->connectionPool
            ->getConnectionForTable($table)
            ->delete($table, ['uid' => self::$uid]);
        self::$uid = 0;
    }

    public function __construct(private readonly ConnectionPool $connectionPool) {}
}
