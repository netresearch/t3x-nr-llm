<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

/**
 * Characters used and the character limit of a translator account for the
 * current billing period (ADR-207).
 *
 * `limit` is 0 when the service reports none; `plan` names the endpoint the
 * figures came from — `free`, `pro`, or `custom` for a configured base URL
 * that is neither.
 *
 * @internal Not part of the @api surface; may change without notice.
 */
final readonly class CharacterQuota
{
    public const PLAN_FREE = 'free';

    public const PLAN_PRO = 'pro';

    public const PLAN_CUSTOM = 'custom';

    public function __construct(
        public int $used,
        public int $limit,
        public string $plan,
    ) {}

    /**
     * Share of the limit already used, in percent, or null when no limit is
     * known — a division by a missing limit is not a percentage.
     */
    public function usedPercent(): ?float
    {
        if ($this->limit <= 0) {
            return null;
        }

        return round($this->used / $this->limit * 100, 1);
    }
}
