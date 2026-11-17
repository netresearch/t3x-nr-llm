<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The approval a skill version held when its usage was recorded (ADR-321).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum SkillApprovalState: string
{
    /**
     * An unrevoked approval named the skill, source and digest; the record names its uid.
     */
    case APPROVED = 'approved';

    /**
     * A context skill, which needs no approval.
     */
    case NONE = 'none';

    /**
     * No unrevoked approval was found, or none could be looked up.
     */
    case UNKNOWN = 'unknown';
}
