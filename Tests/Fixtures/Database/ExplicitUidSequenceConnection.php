<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement;

/**
 * The connection of {@see ExplicitUidSequenceMiddleware}: an INSERT that
 * names uid moves the table's uid sequence once it has run.
 */
final class ExplicitUidSequenceConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        $statement = parent::prepare($sql);
        $table     = ExplicitUidSequenceMiddleware::tableWithExplicitUid($sql);

        return $table === null ? $statement : new ExplicitUidSequenceStatement($statement, $this, $table);
    }

    public function exec(string $sql): int|string
    {
        $affected = parent::exec($sql);
        $table    = ExplicitUidSequenceMiddleware::tableWithExplicitUid($sql);
        if ($table !== null) {
            $this->followUid($table);
        }

        return $affected;
    }

    public function followUid(string $table): void
    {
        parent::exec(ExplicitUidSequenceMiddleware::followUid($table));
    }
}
