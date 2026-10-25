<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\Enum\PendingCallRefusalCause;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;

/**
 * Why the approver gate (ADR-133) or the submitter gate (ADR-150) refused, in
 * a form a surface can act on — shared by {@see ApproverNotPermittedException}
 * and {@see SubmitterNotPermittedException}, whose factories record it.
 *
 * The message stays for the log. A surface that words the refusal for the
 * actor reads {@see getCause()} and, for a denied tool, {@see getDenialReason()}:
 * a switched-off tool is not a gap in the actor's permissions, and saying it
 * is sends them to the wrong person. Both return null for an instance built
 * with the plain constructor.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
trait PendingCallRefusalAccessors
{
    private ?PendingCallRefusalCause $cause = null;

    private ?ToolDenialReason $denialReason = null;

    /**
     * Which of the three refusals this is; null when the exception was not built
     * by one of its factories.
     */
    public function getCause(): ?PendingCallRefusalCause
    {
        return $this->cause;
    }

    /**
     * The tool policy's reason, set only for {@see PendingCallRefusalCause::DENIED_TOOL}
     * — for example {@see ToolDenialReason::TOOL_DISABLED} when an administrator
     * switched the tool off while the run waited.
     */
    public function getDenialReason(): ?ToolDenialReason
    {
        return $this->denialReason;
    }

    /**
     * Build the refusal with its cause recorded — what each factory returns.
     */
    private static function refused(string $runUuid, PendingCallRefusalCause $cause, ?ToolDenialReason $reason, string $message): self
    {
        $exception               = new self($runUuid, $message);
        $exception->cause        = $cause;
        $exception->denialReason = $reason;

        return $exception;
    }
}
