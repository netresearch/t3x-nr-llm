<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyPendingException;

/**
 * A tool that may hand its work to an external operation which finishes
 * later (ADR-281), the A2A delegation tool first.
 *
 * Its {@see ToolInterface::execute()} returns a result as any tool does when
 * the operation answered at once, and throws
 * {@see ToolDependencyPendingException} when it was accepted and has not
 * finished. The loop then suspends the run WAITING_FOR_DEPENDENCY with the
 * call pending; the worker resumes it with the operation's result as that
 * call's tool result, and the tool is never executed again for that call
 * (spec 051 A2A-R4).
 *
 * A model turn that calls such a tool together with any other tool is
 * refused before any of them runs (A2A-R5): at most one dependency is
 * pending per run segment.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface DependencyToolInterface extends ToolInterface {}
