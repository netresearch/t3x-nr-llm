<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use RuntimeException;

/**
 * A WRITING tool executed but its audit step could not be persisted (ADR-111).
 *
 * The agent queue is at-least-once and {@see \Netresearch\NrLlm\Service\Tool\AgentRunPersister::recordStep()}
 * is otherwise fail-soft — a store hiccup for a read-only step is logged and the
 * run continues. That is unacceptable for a tool that changed state: an
 * unrecorded write leaves no evidence the effect happened, and silently
 * continuing would let the run report success over an unaudited mutation. So the
 * runtime throws this instead, failing the run.
 *
 * It carries no {@see \Netresearch\NrLlm\Domain\Enum\FailureClass} of its own, so
 * {@see \Netresearch\NrLlm\Provider\Middleware\FailureClassifier} maps it to
 * {@see \Netresearch\NrLlm\Domain\Enum\FailureClass::UNKNOWN} — deliberately
 * NOT retryable: re-running the run would re-execute the same write, and it
 * already ran once. A queued run is therefore dead-lettered (NOT_RETRYABLE), an
 * interactive one settles FAILED. Never auto-retried.
 */
final class AuditPersistenceFailedException extends RuntimeException
{
    public static function forRun(string $runUuid, string $toolName): self
    {
        return new self(
            sprintf(
                'Run %s: the audit step for writing tool "%s" could not be persisted; failing the run rather than continuing over an unrecorded write.',
                $runUuid !== '' ? $runUuid : 'unknown',
                $toolName !== '' ? $toolName : 'unknown',
            ),
            1785000001,
        );
    }
}
