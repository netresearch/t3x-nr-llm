<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The channel a skill version reached a model call through (ADR-321).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum SkillRole: string
{
    /**
     * An approved version in the system message (ADR-214 item 2).
     */
    case INSTRUCTION = 'instruction';

    /**
     * The approved version of the process the run invokes or holds (ADR-228).
     */
    case PROCESS = 'process';

    /**
     * The delimited, lower-trust block in the first user message.
     */
    case CONTEXT = 'context';
}
