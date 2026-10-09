<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why a write proposal of a guided process was denied (ADR-214, item 9;
 * amends ADR-200).
 *
 * The approval card of a process run answers a proposal in three ways:
 * "apply" approves it, "another variant" and "skip" deny it with this reason.
 * The model reads it as a fixed token beside `decided_by` and either proposes
 * a new variant or moves on. It is a closed type on purpose: the denial text
 * is rendered from the case, never from text a caller supplied.
 *
 * Only the denial of a write proposal in a run that holds a process pin
 * carries one; every other denial carries none and keeps the text of ADR-200.
 *
 * @api
 */
enum ApprovalDenialReason: string
{
    /**
     * The editor wants another proposal for the same point.
     */
    case VARIANT = 'variant';

    /**
     * The editor skips the point; the process moves on.
     */
    case SKIP = 'skip';
}
