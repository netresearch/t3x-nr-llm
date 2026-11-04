<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;

/**
 * The round limits of an agent run as rows of the effective-policy readout
 * (ADR-140; ADR-101, amendment of 2026-11-04): the values the runtime
 * applies, read through the same resolver, not the raw settings.
 *
 * A row of its own beside {@see \Netresearch\NrLlm\Service\Governance\EffectivePolicyReadout}
 * rather than inside it: that readout is core, and core does not depend on the
 * agent module (ADR-090).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class AgentRoundLimitsReadout
{
    /**
     * A configured default above the configured ceiling is not what a run
     * gets: the ceiling is. The value shown is right; the setting beside it
     * says otherwise, so the row says why.
     */
    public const NOTE_DEFAULT_CLAMPED = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.note.roundsDefaultClamped';

    public function __construct(
        private AgentRoundLimits $roundLimits,
    ) {}

    /**
     * @return list<EffectivePolicyRow>
     */
    public function rows(): array
    {
        return [
            new EffectivePolicyRow(
                'agent.defaultMaxIterations',
                (string) $this->roundLimits->defaultRounds(),
                $this->roundLimits::class,
                $this->roundLimits->defaultIsClamped() ? self::NOTE_DEFAULT_CLAMPED : null,
            ),
            new EffectivePolicyRow(
                'agent.maxIterations',
                (string) $this->roundLimits->ceiling(),
                $this->roundLimits::class,
            ),
        ];
    }
}
