<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why a forced source the run asked for did not arrive (ADR-179), or why a
 * continuation dropped the process pin it derived (ADR-228).
 *
 * The two cases are kept apart deliberately. Both resolve to nothing, so a
 * single "dropped" would be easier to produce — and it would flatten two
 * different operator actions with different remedies: a deactivated record can
 * be switched back on, a removed one cannot. A reader who cannot tell them
 * apart has to go looking, which is the situation this record exists to end.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
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

    /**
     * A process pin the continuation derived and dropped because its skill is
     * no longer attached to the configuration (ADR-214 item 6, ADR-228).
     * Detaching a skill ends its pin at the next turn without stopping the run.
     */
    case DETACHED = 'detached';

    /**
     * A process pin the continuation derived and dropped because the turn runs
     * on another configuration than its predecessor did (ADR-214 item 6,
     * ADR-228): the new configuration starts with its own attachments.
     */
    case CONFIGURATION_CHANGED = 'configuration_changed';

    /**
     * The run a continuation names is gone, or the actor may not continue it
     * (ADR-228): whatever process it held is not carried on, and the turn
     * goes on without it. The uid is 0, since nothing says which process
     * that was.
     *
     * One case for three: a run that retention purged, one that never
     * existed, and another user's run. Retention deletes the row and its
     * events and leaves no trace, so a purged run and a uuid that never
     * existed cannot be told apart; and another user's run answers the same,
     * so the notice does not tell whether that run exists.
     */
    case PREDECESSOR_GONE = 'predecessor_gone';
}
