<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;

/**
 * The digest that binds an approval decision to the turn the operator reviewed
 * (ADR-132).
 *
 * A stable hash over a suspended run's pending tool calls. The reviewing surface
 * renders it alongside the calls, the decision carries it back, and
 * {@see ResumeCoordinator::approve()} recomputes it from the freshly-claimed
 * state and refuses a mismatch — so a stale tab, or a second operator whose
 * approval already let the run suspend on a DIFFERENT turn, cannot authorize a
 * turn nobody looked at.
 *
 * ONE definition, deliberately: the digest is a comparison, and two
 * implementations that drift apart would silently compare unequal things. The
 * hash covers the pending calls only — the transcript and the counters change
 * on every round and would make every digest stale.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class PendingTurnDigest
{
    /**
     * The sha256 of a suspended state's pending calls. `serialize()` is the
     * fallback for the (unreachable in practice) case of an unencodable value,
     * so the method always yields a comparable digest instead of an empty hash.
     */
    public function forState(SuspendedRunState $state): string
    {
        $json = json_encode($state->pendingCalls, JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $json !== false ? $json : serialize($state->pendingCalls));
    }
}
