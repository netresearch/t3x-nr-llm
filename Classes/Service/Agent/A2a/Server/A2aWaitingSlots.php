<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The installation's slots for waiting blocking requests (spec 051 A2A-SV9,
 * ADR-283), shared by every PHP process through the database.
 *
 * Slot `n` is one row. A request takes a free slot with one guarded UPDATE,
 * so of two requests that see the same free slot exactly one gets it. The
 * hold carries an expiry beyond the request's own deadline: a request that is
 * killed while it waits never releases its slot, and the slot is free again
 * when the hold expires.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aWaitingSlots
{
    public const TABLE = 'tx_nrllm_a2a_wait_slot';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Take one of $capacity slots until $expires.
     *
     * @return string|null the holder token to release with, null when all are held
     */
    public function take(int $capacity, int $now, int $expires): ?string
    {
        $connection = $this->connection();
        $holder     = bin2hex(random_bytes(16));
        for ($slot = 1; $slot <= $capacity; ++$slot) {
            if ($connection->count('slot', self::TABLE, ['slot' => $slot]) === 0) {
                try {
                    $connection->insert(self::TABLE, ['slot' => $slot, 'holder' => '', 'expires' => 0]);
                } catch (UniqueConstraintViolationException) {
                    // Another request created it first.
                }
            }

            $taken = $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET holder = ?, expires = ? WHERE slot = ? AND (holder = ? OR expires < ?)',
                [$holder, $expires, $slot, '', $now],
                [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
            );
            if ($taken === 1) {
                return $holder;
            }
        }

        return null;
    }

    public function release(string $holder): void
    {
        $this->connection()->update(self::TABLE, ['holder' => '', 'expires' => 0], ['holder' => $holder]);
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
