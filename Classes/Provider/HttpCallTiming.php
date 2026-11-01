<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

/**
 * How long one provider HTTP call took, and what the provider says it spent.
 */
final readonly class HttpCallTiming
{
    /**
     * @param float    $httpMs       wall time of the call in milliseconds,
     *                               secret retrieval and DNS lookup included
     * @param int|null $processingMs the provider's own figure; null when it
     *                               reports none
     */
    public function __construct(
        public float $httpMs,
        public ?int $processingMs,
    ) {}
}
