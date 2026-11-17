<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * A prepared INSERT naming uid, from {@see ExplicitUidSequenceConnection}:
 * once it has run, the table's uid sequence follows.
 */
final class ExplicitUidSequenceStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly ExplicitUidSequenceConnection $connection,
        private readonly string $table,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $result = parent::execute();
        $this->connection->followUid($this->table);

        return $result;
    }
}
