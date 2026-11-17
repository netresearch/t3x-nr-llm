<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

/**
 * Whether a uid is one a record can have: a positive 32-bit integer, the
 * range of TYPO3's uid column on every database it supports.
 *
 * A uid often comes from a request (an AJAX body, a route argument, a CLI
 * argument). MySQL and SQLite compare a larger value and find nothing;
 * PostgreSQL refuses the query ("out of range for type integer"), which
 * turned a "not found" into a database error there. A lookup asks this
 * first and answers "not found" itself.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class StorableUid
{
    /** The largest uid a record can have: the 32-bit signed integer limit. */
    public const LARGEST = 2147483647;

    public static function fits(mixed $uid): bool
    {
        return is_numeric($uid) && (float) $uid >= 1 && (float) $uid <= self::LARGEST;
    }

    /**
     * The uids of `$uids` a record can have, in their order.
     *
     * @param list<int> $uids
     *
     * @return list<int>
     */
    public static function only(array $uids): array
    {
        return array_values(array_filter($uids, self::fits(...)));
    }
}
