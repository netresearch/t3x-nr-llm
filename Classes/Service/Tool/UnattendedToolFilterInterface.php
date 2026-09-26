<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Which tools run without a human approval (ADR-210).
 *
 * For a caller that has no approval step and therefore cannot resume a run
 * that {@see ToolLoopServiceInterface::runLoop()} suspended: it narrows what
 * {@see ToolCallPolicyInterface::filterOfferable()} allows to the tools that
 * never suspend, and offers the model only those.
 *
 * @api
 */
interface UnattendedToolFilterInterface
{
    /**
     * The names, in their given order, of the tools that run without an
     * approval. A name no registered tool carries is left out.
     *
     * @param list<string> $toolNames
     *
     * @return list<string>
     */
    public function unattended(array $toolNames): array;
}
