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
 * Asked before the first effect of every resume of a run — an approval, an
 * input, a stored decision, a resolved dependency (spec 051 A2A-MT4). A guard
 * that names a refusal fails the run; nothing of the resumed segment runs.
 *
 * Register a service implementing this interface; it is tagged
 * `nr_llm.run_resume_guard` automatically. A guard answers for the runs it
 * knows and returns null for every other.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AutoconfigureTag(name: self::TAG)]
interface RunResumeGuardInterface
{
    public const TAG = 'nr_llm.run_resume_guard';

    /**
     * Why the run may not resume, or null when it may.
     */
    public function refusal(AgentRunHandle $handle, AiActorContext $owner): ?string;
}
