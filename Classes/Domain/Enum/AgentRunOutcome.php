<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How a synchronous agent-run segment ended, as returned by the
 * AgentRuntime (ADR-101).
 *
 * Distinct from {@see AgentRunStatus} (the persisted row's lifecycle state):
 * an outcome describes what THIS run() / approve() call produced, including
 * transient distinctions the row does not need — a guardrail denial vs. a
 * guardrail approval requirement both persist as FAILED, and SUSPEND_FAILED
 * persists as FAILED while telling the caller specifically that an approval
 * was required but could not be stored (fail-closed, ADR-092), so no resume
 * must be offered.
 *
 * More cases may be added in minor releases (the queue epic will add
 * queue-related outcomes); consumers must not match exhaustively without a
 * default arm.
 */
enum AgentRunOutcome: string
{
    case COMPLETED = 'completed';
    case AWAITING_APPROVAL = 'awaiting_approval';
    // A called tool suspended the run to request TYPED INPUT from the user
    // (ADR-105). The row is WAITING_FOR_INPUT carrying the declared input schema;
    // a later submitInput() validates the submission and resumes. Distinct from
    // AWAITING_APPROVAL (approve/deny) — this collects data, not a verdict.
    case AWAITING_INPUT = 'awaiting_input';
    case SUSPEND_FAILED = 'suspend_failed';
    case GUARDRAIL_BLOCKED = 'guardrail_blocked';
    case GUARDRAIL_APPROVAL_REQUIRED = 'guardrail_approval_required';
    case FAILED = 'failed';

    // The run was cancelled while executing and the loop stopped
    // cooperatively at the next step boundary (ADR-103). The row is already
    // terminal CANCELLED (the cancel won the guarded transition); this outcome
    // tells the caller their in-flight call ended because of it.
    case CANCELLED = 'cancelled';

    // A queued run failed with a retryable provider error and was put back on
    // the queue for another attempt within its requeue budget (ADR-104). The
    // row is QUEUED again; a delayed message will re-execute it.
    case REQUEUED = 'requeued';

    // A worker executing a queued run lost its lease — the stale-run reaper
    // reclaimed it and another worker owns it now (ADR-104). This worker stops
    // WITHOUT settling; the run belongs to the new owner.
    case LEASE_LOST = 'lease_lost';
}
