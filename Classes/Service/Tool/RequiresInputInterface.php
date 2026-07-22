<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Opt-in marker: a {@see ToolInterface} that also implements this suspends the
 * agent loop to collect TYPED INPUT from the user before it executes (ADR-105).
 *
 * The sibling of {@see RequiresApprovalInterface}: approval collects a verdict,
 * input collects data. A marker (not a method on {@see ToolInterface}) so the
 * existing read-only tools are untouched — the loop only pauses for a tool that
 * opts in. When the model calls such a tool, {@see ToolLoopService} suspends the
 * run WAITING_FOR_INPUT carrying {@see self::getInputSchema()}; a later
 * submitInput() validates the user's submission against that schema and resumes.
 *
 * A tool MUST NOT implement BOTH this and {@see RequiresApprovalInterface}: a
 * combined approval+input pause is unsupported and rejected at registration
 * ({@see ToolRegistry}), because the approval-resume path carries no input and
 * would silently drop the mandatory data.
 */
interface RequiresInputInterface
{
    /**
     * A JSON-Schema subset (``type`` / ``required`` / ``properties``) describing
     * the input the user must supply before this tool executes. Uses the same
     * unbounded ``array<string, mixed>`` convention as tool parameter specs
     * (ADR-082) — no typed schema DTO by design.
     *
     * MUST declare a real shape (a ``type`` or at least one property); a tool
     * with nothing to collect must not implement this interface. A degenerate
     * schema is treated as a programming error at the loop's capture-time gate
     * (ADR-105), never as "accept anything".
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;
}
