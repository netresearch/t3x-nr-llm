<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

/**
 * findByUid() of an Extbase repository that answers null for a uid no record
 * can have ({@see StorableUid}) instead of putting it into the query.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
trait FindsByStorableUid
{
    /**
     * @param int $uid
     */
    public function findByUid($uid)
    {
        if (is_numeric($uid) && !StorableUid::fits($uid)) {
            return null;
        }

        return parent::findByUid($uid);
    }
}
