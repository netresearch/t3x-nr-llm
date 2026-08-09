<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * The approver may not run the write they are releasing (ADR-133).
 *
 * A resumed turn executes under the RUN OWNER's identity (ADR-083), so an
 * approver whose own permissions would not admit a pending write call would
 * otherwise have that call executed on someone else's authority — a confused
 * deputy. The decision therefore passes the same {@see \Netresearch\NrLlm\Service\Tool\ToolCallPolicy}
 * the execution would, evaluated against the APPROVER's live backend user.
 *
 * Thrown BEFORE any execution and after the run has been released back to
 * WAITING_FOR_APPROVAL: nothing ran, and someone who does hold the permission
 * can still decide the turn. Only an APPROVAL is gated — a denial executes
 * nothing.
 *
 * The message names the actor, the tool and the reason, so the refusal is
 * recorded in the log line the coordinator writes and in the surface's own
 * error path. It never carries tool arguments.
 */
final class ApproverNotPermittedException extends AgentRuntimeException
{
    /**
     * The approver's own live permissions do not admit the pending write call.
     */
    public static function forDeniedTool(string $runUuid, AiActorContext $actor, string $toolName, ToolDenialReason $reason): self
    {
        return new self($runUuid, sprintf(
            '%s may not run the write tool "%s" (%s) and therefore may not approve it. (run %s)',
            ucfirst($actor->describe()),
            $toolName !== '' ? $toolName : 'unknown',
            $reason->value,
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }

    /**
     * The approver has no live backend user to evaluate the tool gate against: a
     * service account (whose scopes carry no backend permissions at all), or a
     * uid that no longer resolves to an enabled user. Both are refused rather
     * than passed to the gate as "no user", which would only check the
     * requiresAdmin axis and let every other write through.
     */
    public static function forApproverWithoutPermissions(string $runUuid, AiActorContext $actor, string $toolName): self
    {
        return new self($runUuid, sprintf(
            '%s has no backend permissions to check the write tool "%s" against and therefore may not approve it. (run %s)',
            ucfirst($actor->describe()),
            $toolName !== '' ? $toolName : 'unknown',
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }

    /**
     * A pending entry too corrupt to yield a tool call cannot be classified and
     * cannot be checked — the same fail-closed verdict the write classification
     * gives it.
     */
    public static function forUnreadableCall(string $runUuid, AiActorContext $actor): self
    {
        return new self($runUuid, sprintf(
            '%s may not approve a pending call that cannot be read, so its permissions cannot be checked. (run %s)',
            ucfirst($actor->describe()),
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}
