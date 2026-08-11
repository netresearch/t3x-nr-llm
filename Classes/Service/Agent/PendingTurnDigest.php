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
 * Since ADR-150 the INPUT pause has its own binding, {@see self::forInputState()},
 * and it lives here rather than in a second class for the reason above: one
 * definition per pause kind, both in the one place that defines what "the turn"
 * means.
 *
 * ONE definition, deliberately: the digest is a comparison, and two
 * implementations that drift apart would silently compare unequal things. The
 * hash covers the pending calls only — the transcript and the counters change
 * on every round and would make every digest stale.
 *
 * Neither digest covers the run uuid. It is not a field of the turn, and it
 * cannot be omitted from the comparison anyway: a digest is only ever checked
 * against the state of the run named in the same call, so a digest borrowed
 * from another run can only match when that run's turn is byte-identical — the
 * same tool, the same arguments, the same schema — which is not an escalation.
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
        return $this->hash($state->pendingCalls);
    }

    /**
     * The digest of an INPUT pause (ADR-150): the pending calls PLUS the target
     * tool and the schema the form was rendered from.
     *
     * The two extra fields are what an input pause is decided on. The tool name
     * is what {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService::resumeWithInput()}
     * dispatches the submitted values onto, and the schema is what the operator's
     * form — and the pre-claim validation — were built from. Covering them means
     * a matching digest also proves the values were validated against the schema
     * the run is still suspended on, so the pre-claim verdict transfers to the
     * post-claim state that actually executes.
     *
     * Deliberately NOT folded into {@see self::forState()}: an approval pause
     * carries neither field, and changing that hash would invalidate every
     * approval card already rendered while proving nothing new (ADR-132).
     */
    public function forInputState(SuspendedRunState $state): string
    {
        return $this->hash([
            'pendingCalls'  => $state->pendingCalls,
            'inputToolName' => $state->inputToolName,
            'inputSchema'   => $state->inputSchema,
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function hash(array $payload): string
    {
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $json !== false ? $json : serialize($payload));
    }
}
