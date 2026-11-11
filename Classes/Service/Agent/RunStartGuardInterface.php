<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Asked when a worker has claimed a queued run, before its first segment: the
 * start counterpart of {@see RunResumeGuardInterface}. A run accepted on
 * behalf of a caller whose grant was withdrawn while the run waited in the
 * queue is failed rather than started (spec 051 A2A-MT4).
 *
 * Register a service implementing this interface; it is tagged
 * `nr_llm.run_start_guard` automatically. A guard answers for the runs it
 * knows and returns null for every other.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AutoconfigureTag(name: self::START_TAG)]
interface RunStartGuardInterface
{
    public const START_TAG = 'nr_llm.run_start_guard';

    /**
     * Why the run may not start, or null when it may.
     *
     * @param list<string>|null $allowedToolNames the tools the queued request grants; null is every enabled tool
     */
    public function startRefusal(AgentRunHandle $handle, AiActorContext $actor, ?array $allowedToolNames): ?string;
}
