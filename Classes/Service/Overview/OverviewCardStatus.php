<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Overview;

use Netresearch\NrLlm\Domain\Enum\OverviewCardState;

/**
 * Per-module setup status shown on the overview card.
 *
 * @see OverviewReadinessService
 */
final readonly class OverviewCardStatus
{
    public function __construct(
        public OverviewCardState $state,
        public ?int $count = null,
        public ?int $enabledCount = null,
        public bool $hasDefault = false,
    ) {}

    /**
     * Backed enum value ('ready'|'next'|'empty'|'locked'|'neutral') — convenient
     * for Fluid, which cannot call the enum's methods inline.
     */
    public function getStateValue(): string
    {
        return $this->state->value;
    }

    /**
     * CSS modifier class for the card, for use in Fluid templates.
     */
    public function getCssClass(): string
    {
        return $this->state->cssClass();
    }
}
