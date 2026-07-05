<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Setup state of a module card on the LLM backend overview.
 *
 * Folds the old separate "getting started" stepper onto the module cards:
 * - Ready   — configured / has active entries (green, with a check badge)
 * - Next    — the single recommended next step on the critical path (blue, ring)
 * - Empty   — an optional module with no entries yet (soft blue invitation)
 * - Locked  — a critical step blocked by an earlier one (muted; still clickable)
 * - Neutral — no setup opinion (plain card)
 */
enum OverviewCardState: string
{
    case Ready = 'ready';
    case Next = 'next';
    case EmptyState = 'empty';
    case Locked = 'locked';
    case Neutral = 'neutral';

    /**
     * Check whether a raw string is a valid state value.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * CSS modifier class used by the template / Overview.css.
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Ready => 'is-ready',
            self::Next => 'is-next',
            self::EmptyState => 'is-empty',
            self::Locked => 'is-locked',
            self::Neutral => '',
        };
    }
}
