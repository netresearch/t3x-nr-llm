<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The decision does not name the turn it was made on, or names a different one
 * (ADR-132).
 *
 * Thrown by approve() AFTER the claim, when the digest carried by the
 * {@see \Netresearch\NrLlm\Service\Agent\ApprovalDecision} is null or does not
 * match the freshly loaded state's pending calls. A missing digest and a wrong
 * digest are the same fact — the reviewed turn is not known — so both fail
 * closed here rather than one of them being waved through.
 *
 * The run is released back to WAITING_FOR_APPROVAL before this is thrown: the
 * decision was refused, not consumed, and the operator can re-review the CURRENT
 * turn and decide again.
 */
final class StaleApprovalTurnException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf(
            "The approval does not match the run's current pending turn; it was reviewed against a different (or no) turn. (run %s)",
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}
