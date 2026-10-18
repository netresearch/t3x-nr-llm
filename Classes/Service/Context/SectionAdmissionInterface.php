<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Admission of a new system section against the context window (ADR-214
 * item 7).
 *
 * A section is admitted only when the smallest send {@see ContextWindowManagerInterface::fit()}
 * can reach — the head (the system messages before the first user turn, now
 * carrying the new section, and the first user message) plus the newest turn
 * — stays at or below the budget minus a headroom of 10 % of the budget. The
 * headroom absorbs what the turn adds after the check (the results of the
 * calls still to run); a turn that outgrows it ends legibly as a truncated
 * run, never as an unbounded send. A section that does not pass is refused,
 * never appended and evicted later.
 *
 * Separate from {@see ContextWindowManagerInterface} so adding it breaks no
 * implementation of that interface.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface SectionAdmissionInterface
{
    /** Share of the budget held back as headroom when a section is admitted. */
    public const HEADROOM_FRACTION = 0.10;

    /**
     * Start measuring a new run, or a new segment of a suspended one: the
     * calibration another run raised on a shared instance does not carry
     * into an admission made before this run's first send.
     */
    public function beginRun(): void;

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages              the transcript the section would join
     * @param string                                 $section               the text that would be appended to the head's system message
     * @param list<array<string, mixed>>             $toolSpecs             the tool schemas on the wire for the next send
     * @param string|null                            $effectiveSystemPrompt the prompt the shaping stage prepends when the transcript carries no system message
     * @param Model|null                             $resolvedModel         the model the send runs on; null keeps the configuration's own. A window that is not known (no model, a context length of 0 or less) admits nothing, and neither does one the output reserve fills
     */
    public function admitsSection(
        array $messages,
        string $section,
        LlmConfiguration $configuration,
        ?ChatOptions $options,
        array $toolSpecs = [],
        ?string $effectiveSystemPrompt = null,
        ?Model $resolvedModel = null,
    ): bool;
}
