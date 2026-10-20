<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why the runtime refused to start or continue a process (ADR-214 item 10,
 * ADR-228), for a caller that reacts to the reason rather than showing the
 * message: the chat offers "start a new conversation" on a missing
 * predecessor, for example.
 *
 * @api
 */
enum ProcessInvocationRefusal: string
{
    /**
     * The actor may not use the run's configuration.
     */
    case CONFIGURATION_NOT_PERMITTED = 'configuration_not_permitted';
    /**
     * The invoked skill is not attached to the run's configuration.
     */
    case SKILL_NOT_ATTACHED = 'skill_not_attached';
    /**
     * The invoked skill is disabled or orphaned.
     */
    case SKILL_DISABLED = 'skill_disabled';
    /**
     * The invoked skill's trust level is below the configured floor, so the
     * composer does not admit it (ADR-061).
     */
    case SKILL_NOT_ADMITTED = 'skill_not_admitted';
    /**
     * The invoked skill's approved version is not a process.
     */
    case NOT_A_PROCESS = 'not_a_process';
    /**
     * The invoked skill's current version is not an approved instruction.
     */
    case NOT_AN_INSTRUCTION = 'not_an_instruction';
    /**
     * The conversation already holds another process.
     */
    case PROCESS_HELD = 'process_held';
    /**
     * The configuration sets `require_second_approver`.
     */
    case SECOND_APPROVER_REQUIRED = 'second_approver_required';
    /**
     * The actor is a service account, which can never decide a process run.
     */
    case SERVICE_ACCOUNT = 'service_account';
    /**
     * The backend user the run executes as may not read the subject record.
     */
    case SUBJECT_NOT_READABLE = 'subject_not_readable';
    /**
     * The predecessor run does not exist, or the actor is neither its
     * initiator nor an administrator. Not produced any more: such a
     * continuation goes on without the process and reports it as a dropped
     * source ({@see DroppedSourceReason::PREDECESSOR_GONE}), because a run
     * that retention purged would otherwise refuse every later turn (ADR-228).
     */
    case PREDECESSOR_MISSING = 'predecessor_missing';
    /**
     * The predecessor holds a process and still waits or runs.
     */
    case PREDECESSOR_NOT_FINISHED = 'predecessor_not_finished';
    /**
     * What the predecessor recorded for its conversation could not be read.
     */
    case LINEAGE_UNREADABLE = 'lineage_unreadable';
    /**
     * The process pin the predecessor holds no longer holds: revoked,
     * disabled, orphaned, distrusted, or no longer a process. The run records
     * its lineage without the process, so the next turn goes on without it.
     */
    case PIN_WITHDRAWN = 'pin_withdrawn';
    /**
     * A waiting turn was to resume under a process that was ended from
     * another turn of the conversation since it suspended.
     */
    case PROCESS_ENDED = 'process_ended';
    /**
     * The run could not record its process, or was not persisted at all.
     */
    case LINEAGE_NOT_STORED = 'lineage_not_stored';
}
