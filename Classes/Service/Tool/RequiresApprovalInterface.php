<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Opt-in marker: a {@see ToolInterface} that also implements this must be
 * approved by a human before the agent loop executes it (ADR-084).
 *
 * A marker (not a method on {@see ToolInterface}) so the 41 existing read-only
 * tools are untouched and their behaviour is provably unchanged — the loop only
 * pauses for a tool that opts in. A write/side-effecting tool implements this to
 * require a human in the loop; {@see ToolLoopService} then suspends the run when
 * the model calls it and resumes only on an explicit approval.
 */
interface RequiresApprovalInterface {}
