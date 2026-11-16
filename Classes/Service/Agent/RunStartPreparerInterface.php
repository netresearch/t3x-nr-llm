<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Service\Agent\Exception\RunStartRefusedException;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Asked when a worker has claimed a queued run, after every
 * {@see RunStartGuardInterface} let it through and before its first segment:
 * a preparer may add to the run's request what only the worker may obtain,
 * or refuse the run (spec 051 A2A-FILE3, A2A-FILE4).
 *
 * A refusal ({@see RunStartRefusedException}) fails the run and is not
 * retried; any other exception goes through the failure recovery of a
 * claimed run. A preparer answers for the runs it knows and returns every
 * other request unchanged.
 *
 * Register a service implementing this interface; it is tagged
 * `nr_llm.run_start_preparer` automatically.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AutoconfigureTag(name: self::TAG)]
interface RunStartPreparerInterface
{
    public const TAG = 'nr_llm.run_start_preparer';

    /**
     * @throws RunStartRefusedException
     */
    public function prepare(AgentRunHandle $handle, AgentRunRequest $request): AgentRunRequest;
}
