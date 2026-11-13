<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * The storage receipt of a durable decision (ADR-281, spec 051 A2A-MT2): the
 * decision is stored and the run is QUEUED for the worker. Nothing of it has
 * run yet; the worker checks the rights again before the first effect, and
 * may still refuse it then.
 *
 * @api
 */
final readonly class DecisionReceipt
{
    public function __construct(
        public string $decisionId,
        public string $runUuid,
        public string $kind,
        public int $pauseGeneration,
        public int $storedAt,
    ) {}
}
