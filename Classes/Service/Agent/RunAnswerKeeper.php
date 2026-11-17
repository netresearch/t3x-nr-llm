<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;

/**
 * Keeps the answer of a run that completed where nobody watched it arrive
 * (ADR-234): a queued run in the worker, and every run that completes in a
 * resume, whoever's process that is.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RunAnswerKeeper
{
    public function __construct(
        private AgentRunPersister $persister,
        private ?PrivacyPolicyInterface $privacyPolicy = null,
    ) {}

    /**
     * Keep the answer of a completed run under the privacy level: sealed in
     * the run row and purged with it, readable where the run is (ADR-234).
     */
    public function keep(AgentRunHandle $handle, AgentRunResult $result): void
    {
        if ($result->outcome === AgentRunOutcome::COMPLETED) {
            $this->keepContent($handle, $result->loopResult?->finalContent);
        }
    }

    /**
     * Keep the final content of a run that is about to complete: a resume
     * writes it before its completion commits, so the two are seen together,
     * and only while $whileClaimedBy still holds the run.
     */
    public function keepContent(AgentRunHandle $handle, ?string $answer, ?string $whileClaimedBy = null): void
    {
        if ($answer === null || $answer === '') {
            return;
        }

        // No policy, no answer: what may be kept is the policy's to say.
        $global = $this->privacyPolicy?->level();
        $level  = $this->persister->privacyLevelOf($handle) ?? $global;
        if (!$level instanceof PrivacyLevel || $level === PrivacyLevel::NONE) {
            return;
        }

        // A level a provider or a task tightened to means what it says (ADR-242):
        // only the global default keeps an answer at `metadata`.
        if ($level !== $global && !$level->persistsContent()) {
            return;
        }

        if ($level->requiresRedaction()) {
            $answer = $this->privacyPolicy?->filterContentAt($answer, $level) ?? '';
        }

        $this->persister->recordAnswer($handle, $answer, $whileClaimedBy);
    }
}
