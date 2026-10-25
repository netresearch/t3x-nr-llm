<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why the approver gate (ADR-133) refused an approval — one case per factory of
 * {@see \Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException}.
 *
 * The three causes ask for different remedies, so a surface that tells the
 * approver what to do next has to tell them apart: a denied tool names its
 * {@see ToolDenialReason} (a switched-off tool is the administrator's to
 * re-enable, not the approver's permissions to widen), an approver without a
 * backend user can never approve a write, and an unreadable call cannot be
 * approved by anyone.
 */
enum ApprovalRefusalCause: string
{
    /**
     * The tool policy denied the pending write for the approver; the exception carries the reason.
     */
    case DENIED_TOOL = 'deniedTool';

    /**
     * The approver has no live backend user the policy could be evaluated against.
     */
    case APPROVER_WITHOUT_PERMISSIONS = 'approverWithoutPermissions';

    /**
     * The pending entry is too corrupt to yield a tool call, so it cannot be checked.
     */
    case UNREADABLE_CALL = 'unreadableCall';
}
