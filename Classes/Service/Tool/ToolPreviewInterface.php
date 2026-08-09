<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Opt-in: a tool that can describe, in human-readable lines, what the call it
 * was handed WOULD do (ADR-136).
 *
 * The lines are produced by {@see ToolLoopService} at the moment the run
 * suspends for approval, in the RUN's actor context, and are persisted with the
 * suspended state so the approval card and both playground surfaces can show
 * them. That timing is the whole design: it gives the preview a caller (the
 * loop), a display (the approval card) and the only identity that may read the
 * target — the run's, never the reviewing administrator's (ADR-083).
 *
 * A marker-style optional interface like {@see ToolEffectInterface} and
 * {@see RequiresApprovalInterface}: the forty-odd read-only builtins are
 * untouched, and a tool that does not implement it simply has no preview line
 * on the card.
 *
 * Contract for implementors:
 *
 * - The lines describe INTENT. They are a snapshot of the moment the run paused,
 *   not a reservation: nothing prevents a human from editing the same record
 *   before the approval lands (ADR-136).
 * - A refusal is a legitimate preview. A call the tool would reject should say
 *   so — that is exactly what the approver needs to know.
 * - Return at most a handful of short lines. The loop truncates and caps what it
 *   persists, because the state is encrypted and re-read on every resume.
 * - Read only what the ACTING user of the run may read, and authorise against
 *   the explicit user from the {@see ToolExecutionContext} — never the ambient
 *   `$GLOBALS['BE_USER']` (ADR-083).
 * - Never write. A preview that mutates would defeat the pause it decorates.
 *
 * Throwing is survivable: the loop catches it and renders a line saying the
 * preview failed, so the approver knows they are deciding blind rather than
 * seeing an empty card.
 */
interface ToolPreviewInterface
{
    /**
     * Human-readable lines describing what this call would do.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array;
}
