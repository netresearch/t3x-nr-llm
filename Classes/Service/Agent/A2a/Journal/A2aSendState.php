<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Journal;

/**
 * The state of one send intent (spec 051 A2A-C4, A2A-C6).
 *
 * An intent starts `intended` and ends in exactly one of the four others.
 * `outcome_unknown` is final: such an intent is never sent again
 * automatically; resending creates a new intent with a new message id.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aSendState: string
{
    /**
     * Committed before the request left; no response read yet.
     */
    case INTENDED = 'intended';

    /**
     * A task came back; its id and context id are stored.
     */
    case ACCEPTED = 'accepted';

    /**
     * A message came back instead of a task.
     */
    case ANSWERED = 'answered';

    /**
     * A JSON-RPC error came back, or the client refused locally.
     */
    case REFUSED = 'refused';

    /**
     * The request may have reached the remote and no response was read.
     */
    case OUTCOME_UNKNOWN = 'outcome_unknown';

    public function isSettled(): bool
    {
        return $this !== self::INTENDED;
    }
}
