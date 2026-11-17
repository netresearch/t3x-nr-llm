<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Whether an evaluation was measured on the model that actually answered the
 * call it is recorded for (ADR-322).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum SkillEvidenceFit: string
{
    /**
     * The evaluated model is the one the provider named on the call's answer.
     */
    case MATCH = 'match';

    /**
     * Another model answered the call, for example after a fallback.
     */
    case OTHER_MODEL = 'other_model';

    /**
     * The call's answer named no model, or the evaluation names none.
     */
    case UNKNOWN = 'unknown';
}
