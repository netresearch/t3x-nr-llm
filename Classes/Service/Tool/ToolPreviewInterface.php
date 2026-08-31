<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

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
 * - The lines describe INTENT, and since ADR-184 they are also the RESERVATION:
 *   the loop re-runs this method when the run resumes and refuses the approved
 *   call if the lines no longer match the ones the approver was shown. ADR-136
 *   said the opposite and named what would reopen it — a tool whose write is a
 *   relative change, where the "before" is an operand rather than decoration.
 * - **A preview must therefore be a pure function of the subject's state and the
 *   arguments.** A line carrying a clock, a random value or an unstable ordering
 *   makes every approval of that tool stale on the first resume, forever, and
 *   nothing else will report why.
 * - A refusal is a legitimate preview. A call the tool would reject should say
 *   so — that is exactly what the approver needs to know.
 * - Return at most a handful of short lines. The loop truncates and caps what it
 *   persists, because the state is encrypted and re-read on every resume.
 * - Read only what the ACTING user of the run may read, and authorise against
 *   the explicit user from the {@see ToolExecutionContext} — never the ambient
 *   `$GLOBALS['BE_USER']` (ADR-083).
 * - Answer {@see self::mayViewerReadPreview()} for the person LOOKING at the
 *   card. Producing a preview and showing it are two authorisations, because
 *   the run owner and the approver are not the same person.
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

    /**
     * May THIS backend user — the one rendering the approval card, not the run's
     * acting user — be shown the preview of this call?
     *
     * The production check in {@see self::previewCall()} authorises the RUN
     * OWNER. The approver is a different person with a different, tool-level
     * authority (ADR-130/133), so without a second check the card would show
     * them the current state of a record they hold no permission on. The tool
     * answers because only it knows which record its arguments name.
     *
     * Read-only, and cheap: it runs once per pending call every time the inbox
     * is rendered. Return false when in doubt — the card then says the preview
     * is withheld, which is a worse card but never a disclosure. It is the
     * FACTORY's job to fail closed when the tool cannot be asked at all
     * ({@see \Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory}).
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool;
}
