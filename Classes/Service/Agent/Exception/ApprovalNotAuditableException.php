<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * A write-declaring turn was approved but the decision could not be recorded
 * (ADR-132).
 *
 * {@see \Netresearch\NrLlm\Service\Tool\AgentRunPersister::recordApproval()} is
 * fail-soft by default — a store hiccup on a read-only turn is logged and the
 * run continues. That is unacceptable when the turn declares a write: executing
 * it would change state on an authority no one can later point to. The write
 * STEP is already fail-closed ({@see AuditPersistenceFailedException}); this
 * closes the decision that authorised it.
 *
 * Thrown BEFORE any execution and after the run has been released back to
 * WAITING_FOR_APPROVAL, so nothing ran and the decision can be retried once the
 * store recovers.
 */
final class ApprovalNotAuditableException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf(
            'The approval of a write-declaring turn could not be recorded; the run was released instead of executing over an unaudited decision. (run %s)',
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}
