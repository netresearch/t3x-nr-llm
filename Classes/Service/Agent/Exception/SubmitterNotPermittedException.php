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
 * The submitter may not run the tool whose input they are supplying (ADR-150).
 *
 * The input-path sibling of {@see ApproverNotPermittedException}, and it exists
 * for the same confused deputy: a resumed turn executes under the RUN OWNER's
 * identity (ADR-083), so a submitter whose own permissions would not admit the
 * pending call would otherwise have that call executed — with arguments they
 * supplied — on someone else's authority.
 *
 * It is a SEPARATE exception because the gate it comes from is a different rule,
 * not a different wording: the approver gate checks only the calls that declare
 * a write, and an input-requiring tool declares no effect (ADR-105 / ADR-134),
 * so "writes only" would check nothing at all. The submitter gate therefore
 * checks EVERY pending call plus the declared input tool. See ADR-150.
 *
 * Thrown BEFORE any execution and after the run has been released back to
 * WAITING_FOR_INPUT: nothing ran, and someone who does hold the permission can
 * still submit. The message names the actor, the tool and the reason; it never
 * carries tool arguments or the submitted values.
 */
final class SubmitterNotPermittedException extends AgentRuntimeException
{
    /**
     * The submitter's own live permissions do not admit the pending call.
     */
    public static function forDeniedTool(string $runUuid, AiActorContext $actor, string $toolName, ToolDenialReason $reason): self
    {
        return new self($runUuid, sprintf(
            '%s may not run the tool "%s" (%s) and therefore may not supply its input. (run %s)',
            ucfirst($actor->describe()),
            $toolName !== '' ? $toolName : 'unknown',
            $reason->value,
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }

    /**
     * The submitter has no live backend user to evaluate the tool gate against:
     * a service account (whose scopes carry no backend permissions at all), or a
     * uid that no longer resolves to an enabled user. Both are refused rather
     * than passed to the gate as "no user", which would only check the
     * requiresAdmin axis and let every other tool through.
     */
    public static function forSubmitterWithoutPermissions(string $runUuid, AiActorContext $actor, string $toolName): self
    {
        return new self($runUuid, sprintf(
            '%s has no backend permissions to check the tool "%s" against and therefore may not supply its input. (run %s)',
            ucfirst($actor->describe()),
            $toolName !== '' ? $toolName : 'unknown',
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }

    /**
     * A pending entry too corrupt to yield a tool call cannot be classified and
     * cannot be checked — the same fail-closed verdict the approver gate gives
     * it.
     */
    public static function forUnreadableCall(string $runUuid, AiActorContext $actor): self
    {
        return new self($runUuid, sprintf(
            '%s may not submit input for a pending call that cannot be read, so its permissions cannot be checked. (run %s)',
            ucfirst($actor->describe()),
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}
