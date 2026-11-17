<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What a skill usage record states about a skill version (ADR-321).
 *
 * A skill that was only configured or only offered in the load catalogue
 * has no record at all: it never reached a model call.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum SkillUsageState: string
{
    /**
     * The load tool appended the section; no model call has carried it yet.
     */
    case LOADED = 'loaded';

    /**
     * The version was part of the request of the record's round.
     */
    case PASSED = 'passed';
}
