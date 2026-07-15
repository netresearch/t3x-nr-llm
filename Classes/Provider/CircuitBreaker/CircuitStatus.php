<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

/**
 * The three states of a per-provider circuit (ADR-063).
 *
 *  - Closed:   normal operation; the provider call is attempted and failures
 *              are counted.
 *  - Open:     recent failures crossed the threshold; calls fail fast for the
 *              cooldown window instead of waiting on a timeout.
 *  - HalfOpen: the cooldown has elapsed; a single probe call is allowed to test
 *              whether the provider has recovered.
 *
 * The status is DERIVED from {@see CircuitState} (failure count + open
 * timestamp) against the current clock and configured cooldown — it is never
 * persisted on its own, so there is no untrusted string to validate back into
 * this enum.
 */
enum CircuitStatus: string
{
    case Closed   = 'closed';
    case Open     = 'open';
    case HalfOpen = 'half_open';
}
