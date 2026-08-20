<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why a forced source the run asked for did not arrive (ADR-179).
 *
 * The two cases are kept apart deliberately. Both resolve to nothing, so a
 * single "dropped" would be easier to produce — and it would flatten two
 * different operator actions with different remedies: a deactivated record can
 * be switched back on, a removed one cannot. A reader who cannot tell them
 * apart has to go looking, which is the situation this record exists to end.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
enum DroppedSourceReason: string
{
    /**
     * The record exists and is switched off.
     *
     * Detected by resolving the same uid twice: the existence lookup finds it,
     * the enabled-only lookup does not. That difference is the whole signal —
     * without both lookups the two reasons are indistinguishable.
     */
    case DEACTIVATED = 'deactivated';

    /**
     * Nothing with that uid resolves at all.
     *
     * Covers a deleted record and a uid that never existed. Those are one case
     * from the run's side: it asked for something it did not get, and no record
     * remains to say which of the two it was.
     */
    case GONE = 'gone';
}
