<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * A live handle to an in-flight agent run (ADR-081).
 *
 * Returned by {@see AgentRunPersister::begin()} and threaded back into
 * {@see AgentRunPersister::recordStep()} and the settle methods. It carries the
 * new run's primary key and uuid plus the per-run event sequence counter — the
 * only mutable state a run needs while it executes. The persister itself is a
 * stateless singleton, so this handle (not the service) owns the sequence.
 */
final class AgentRunHandle
{
    /**
     * The next event's zero-based sequence number, incremented as each step is
     * recorded.
     */
    public int $sequence = 0;

    public function __construct(
        public readonly int $runUid,
        public readonly string $uuid,
    ) {}
}
