<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What became of an approval request for a skill version (ADR-214 item 2).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
enum SkillApprovalOutcome: string
{
    /**
     * The version the approver saw is the current one and is now approved.
     */
    case APPROVED = 'approved';

    /**
     * The record changed since the form was rendered: the approver saw another version.
     */
    case REFUSED_STALE = 'refused_stale';

    /**
     * The record fails its integrity check: its stored fields are not what the sync wrote.
     */
    case REFUSED_INTEGRITY = 'refused_integrity';

    /**
     * The record carries no version digest yet (a legacy row): run the upgrade wizard or a sync first.
     */
    case REFUSED_LEGACY = 'refused_legacy';

    /**
     * The skill is orphaned: it no longer exists upstream.
     */
    case REFUSED_ORPHANED = 'refused_orphaned';

    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }
}
