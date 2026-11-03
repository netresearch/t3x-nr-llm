<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Cost, requests and tokens one extension's calls recorded in a period
 * (ADR-248). Cost is the recorded point-in-time estimate in US dollars.
 *
 * @api
 */
final readonly class ExtensionUsageTotals
{
    public function __construct(
        public float $estimatedCost,
        public int $requests,
        public int $tokens,
    ) {}
}
