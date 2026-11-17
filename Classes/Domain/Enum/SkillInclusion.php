<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How a skill version came into a run's transcript (ADR-321).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum SkillInclusion: string
{
    /**
     * Composed when the run's prompt was assembled.
     */
    case COMPOSED = 'composed';

    /**
     * Loaded on demand by the model through the load tool (ADR-214 item 4).
     */
    case LOADED = 'loaded';

    /**
     * Held by a resumed segment, re-checked before it continued (ADR-214 item 6).
     */
    case CARRIED = 'carried';
}
