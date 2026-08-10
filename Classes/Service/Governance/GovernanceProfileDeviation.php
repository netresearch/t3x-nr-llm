<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

/**
 * One key where the effective governance differs from a profile (ADR-145).
 *
 * Carries where to change it, because the readout has no apply path and never
 * will (ADR-140): an operator who is told a value is wrong and not told where
 * it lives has been given half an answer.
 *
 * @internal
 */
final readonly class GovernanceProfileDeviation
{
    /**
     * @param string      $key      the governance key, as {@see EffectivePolicyRow::$key} names it
     * @param string|null $current  the value the runtime applies now; null when its resolver could not be asked
     * @param string      $expected what the profile describes
     * @param string      $whereKey localisation key naming where an operator changes it
     */
    public function __construct(
        public string $key,
        public ?string $current,
        public string $expected,
        public string $whereKey,
    ) {}

    /**
     * Whether the difference is "we could not read it" rather than "it is set
     * to something else".
     *
     * Worth separating in the display: an unreadable resolver is a different
     * problem from a deliberate divergence, and telling an operator to change a
     * setting they may already have set correctly wastes their time.
     */
    public function isUnknown(): bool
    {
        return $this->current === null;
    }
}
