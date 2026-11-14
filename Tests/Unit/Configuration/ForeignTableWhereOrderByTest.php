<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AbstractItemProvider splits foreign_table_where into WHERE, ORDER BY and
 * GROUP BY with a pattern that accepts the identifier quote of SQLite and
 * PostgreSQL but not that of MySQL and MariaDB. A {#table}.{#field} marker
 * after ORDER BY therefore stays inside the WHERE clause on MySQL and MariaDB
 * and the query fails, while the SQLite-based test matrix passes.
 */
#[CoversNothing]
final class ForeignTableWhereOrderByTest extends TestCase
{
    #[Test]
    public function noForeignTableWhereQuotesIdentifiersAfterOrderBy(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3) . '/Configuration/TCA/*.php') ?: [] as $file) {
            // Read as text: other tests require_once these files, and a second
            // require here would hand them `true` instead of the array.
            $source = (string) file_get_contents($file);
            preg_match_all('/\'foreign_table_where\'\s*=>\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $where = $match[2] ?? $match[1] ?? '';
                if (preg_match('/\b(ORDER|GROUP)\s+BY\b.*\{#/is', $where) === 1) {
                    $offenders[] = basename($file) . ': ' . $where;
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
