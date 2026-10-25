<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why the approver gate (ADR-133) or the submitter gate (ADR-150) refused to let
 * an actor act on a pending call — one case per factory of
 * {@see \Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException}
 * and of {@see \Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException}.
 *
 * The three causes ask for different remedies, so a surface that tells the
 * actor what to do next has to tell them apart: a denied tool names its
 * {@see ToolDenialReason} (a switched-off tool is the administrator's to
 * re-enable, not the actor's permissions to widen), an actor without a backend
 * user can never be checked, and an unreadable call cannot be acted on by
 * anyone.
 */
enum PendingCallRefusalCause: string
{
    /**
     * The tool policy denied the pending call for the actor; the exception carries the reason.
     */
    case DENIED_TOOL = 'deniedTool';

    /**
     * The actor has no live backend user the policy could be evaluated against.
     */
    case NO_BACKEND_PERMISSIONS = 'noBackendPermissions';

    /**
     * The pending entry is too corrupt to yield a tool call, so it cannot be checked.
     */
    case UNREADABLE_CALL = 'unreadableCall';
}
