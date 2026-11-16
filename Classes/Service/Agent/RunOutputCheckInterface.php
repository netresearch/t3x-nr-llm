<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Checks the final answer of a run after its loop and before its completion
 * is stored (ADR-302).
 *
 * The run's starter decides on the server whether its answer is checked:
 * nothing in a request a client sends switches it. A check answers for the
 * runs it knows and returns {@see RunOutputCheckPlan::none()} for every other.
 *
 * The check runs outside every transaction: it may call a model, and the run
 * row is locked only for the completing write that follows.
 *
 * Register a service implementing this interface; it is tagged
 * `nr_llm.run_output_check` automatically.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AutoconfigureTag(name: self::TAG)]
interface RunOutputCheckInterface
{
    public const TAG = 'nr_llm.run_output_check';

    /**
     * Whether and how this run's answer is checked. Asked once per segment,
     * before the loop. An error fails the run.
     */
    public function planFor(AgentRunHandle $handle, bool $start): RunOutputCheckPlan;

    /**
     * Check the answer of a run this check planned for, and keep the result
     * with the run. Never throws: a check that cannot run reports so.
     */
    public function check(AgentRunHandle $handle, string $answer): RunOutputVerdict;
}
