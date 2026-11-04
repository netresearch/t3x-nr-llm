<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

/**
 * Per-model call history for routing (ADR-256).
 *
 * @internal
 */
interface ModelCallHistoryInterface
{
    /**
     * Whether the operator switched the call-history signals on
     * (`routing.callHistory`). Off by default: these figures change which model
     * serves a call, and an upgrade must not switch that on.
     */
    public function enabled(): bool;

    /**
     * What every model's recent calls took and cost, per operation — read once
     * per decision, not once per candidate.
     *
     * @return array<string, ModelCallStats> keyed by {@see ModelCallStats::key()}:
     *                                       the provider's adapter type as
     *                                       telemetry records it, the model id,
     *                                       and a {@see \Netresearch\NrLlm\Provider\Middleware\ProviderOperation} value
     */
    public function all(): array;
}
