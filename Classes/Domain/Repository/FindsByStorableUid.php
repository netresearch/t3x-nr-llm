<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

/**
 * findByUid() that answers null for a uid no record can have, instead of
 * putting it into the query.
 *
 * A uid comes from a request often enough (an AJAX body, a route argument).
 * A record's uid is a positive 32-bit integer on every database TYPO3
 * supports; MySQL and SQLite compare a larger value and find nothing,
 * PostgreSQL refuses the query ("out of range for type integer"), which
 * turned a "not found" into a database error there.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
trait FindsByStorableUid
{
    /** The largest uid a record can have: the 32-bit signed integer limit. */
    private const LARGEST_STORABLE_UID = 2147483647;

    /**
     * @param int $uid
     */
    public function findByUid($uid)
    {
        if (is_int($uid) && ($uid < 1 || $uid > self::LARGEST_STORABLE_UID)) {
            return null;
        }

        return parent::findByUid($uid);
    }
}
